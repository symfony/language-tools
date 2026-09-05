<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\ConfigurationIndex;
use Symfony\Lsp\Feature\Configuration\ConfigurationNode;
use Symfony\Lsp\Feature\Configuration\PhpConfigurationAnalyzer;
use Symfony\Lsp\Feature\Configuration\PhpConfigurationOccurrence;
use Symfony\Lsp\Feature\Configuration\XmlConfigurationAnalyzer;
use Symfony\Lsp\Feature\Configuration\XmlConfigurationOccurrence;
use Symfony\Lsp\Feature\Configuration\XmlConfigurationStructureError;
use Symfony\Lsp\Feature\Configuration\YamlIndentationAnalyzer;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;

final class ConfigurationAnalyzerTest extends TestCase
{
    public function testAnalyzesCompleteAndIncompletePhpDslChains(): void
    {
        $analyzer = new PhpConfigurationAnalyzer(new TolerantPhpParser(new Parser()), new PhpCommentParser());
        $index = $this->index();
        $text = '<?php function configure(FrameworkConfig $options) { $options->router()->utf8(true); }';

        self::assertSame([
            ['path' => ['framework', 'router'], 'literal' => null],
            ['path' => ['framework', 'router', 'utf8'], 'literal' => true],
        ], array_map(static fn (PhpConfigurationOccurrence $occurrence): array => ['path' => $occurrence->path, 'literal' => $occurrence->literal?->scalarValue], $analyzer->occurrences($text, $index)));
        self::assertSame(['framework', 'router', 'utf8'], $analyzer->resolveNode($text, $index, strpos($text, 'utf8') + 1)[0] ?? null);

        $incomplete = '<?php function configure(FrameworkConfig $options) { $options->router()->ut';
        self::assertSame(
            ['path' => ['framework', 'router'], 'prefix' => 'ut', 'start' => \strlen($incomplete) - 2],
            $analyzer->completionContext($incomplete, $index, \strlen($incomplete)),
        );

        $withDigit = '<?php function configure(FrameworkConfig $options) { $options->psr3()->en';
        self::assertSame(
            ['path' => ['framework', 'psr_3'], 'prefix' => 'en', 'start' => \strlen($withDigit) - 2],
            $analyzer->completionContext($withDigit, $index, \strlen($withDigit)),
        );

        $rootWithDigit = '<?php function configure(Psr3Config $options) { $options->enabled(true); }';
        self::assertSame(
            [['path' => ['psr_3', 'enabled'], 'literal' => true]],
            array_map(static fn (PhpConfigurationOccurrence $occurrence): array => ['path' => $occurrence->path, 'literal' => $occurrence->literal?->scalarValue], $analyzer->occurrences($rootWithDigit, $index)),
        );
        $incompleteRootWithDigit = '<?php function configure(Psr3Config $options) { $options->en';
        self::assertSame(
            ['path' => ['psr_3'], 'prefix' => 'en', 'start' => \strlen($incompleteRootWithDigit) - 2],
            $analyzer->completionContext($incompleteRootWithDigit, $index, \strlen($incompleteRootWithDigit)),
        );

        $aliasedRoot = '<?php use Symfony\Config\FrameworkConfig as WebConfig; function configure(WebConfig $web) { $web->router()->ut';
        self::assertSame(
            ['path' => ['framework', 'router'], 'prefix' => 'ut', 'start' => \strlen($aliasedRoot) - 2],
            $analyzer->completionContext($aliasedRoot, $index, \strlen($aliasedRoot)),
        );

        foreach ([
            "<?php function configure(FrameworkConfig \$options) { \$options\n    ->router()\n    ->ut",
            '<?php function configure(FrameworkConfig $options) { $options?->router()->ut',
            '<?php function configure(FrameworkConfig $options) { $options->router()/* here */->ut',
            "<?php function configure(FrameworkConfig \$options) { \$options?->router()\n    // enable UTF-8\n    -> /* here */ ut",
            '<?php function configure(FrameworkConfig $options) { $options->router()->utf8(true)->ut',
        ] as $formatted) {
            self::assertSame(
                ['path' => ['framework', 'router'], 'prefix' => 'ut', 'start' => \strlen($formatted) - 2],
                $analyzer->completionContext($formatted, $index, \strlen($formatted)),
                $formatted,
            );
        }

        foreach ([
            '<?php function configure(FrameworkConfig $options) { $options->router()',
            '<?php function configure(FrameworkConfig $options) { Framework::create()->ut',
            '<?php function configure(FrameworkConfig $options) { router()->ut',
            '<?php function configure(FrameworkConfig $options) { $options->router()[0]->ut',
            '<?php class Configurator { private FrameworkConfig $options; public function configure(): void { $this->options->ut',
            '<?php function configure(FrameworkConfig $options) { // $options->router()->ut',
            "<?php function configure(FrameworkConfig \$options) { \$options->router()->\n// \$options->ut",
            '<?php function configure(FrameworkConfig $options) { $options->router()->/* $options->ut',
        ] as $unsupported) {
            self::assertNull($analyzer->completionContext($unsupported, $index, \strlen($unsupported)), $unsupported);
        }
    }

    public function testAnalyzesChainsWithNullsafeCallsCommentsAndDynamicMethods(): void
    {
        $analyzer = new PhpConfigurationAnalyzer(new TolerantPhpParser(new Parser()), new PhpCommentParser());
        $index = $this->index();
        $text = <<<'PHP'
            <?php
            function configure(FrameworkConfig $options): void
            {
                $options?->router()
                    // enable UTF-8
                    -> /* here */ utf8(true);
                $options->$dynamic();
                $options->{'router'}();
            }
            PHP;

        $occurrences = $analyzer->occurrences($text, $index);
        self::assertSame([['framework', 'router'], ['framework', 'router', 'utf8']], array_map(static fn (PhpConfigurationOccurrence $occurrence): array => $occurrence->path, $occurrences));
        self::assertSame([strpos($text, 'router'), strpos($text, 'utf8')], array_map(static fn (PhpConfigurationOccurrence $occurrence): int => $occurrence->startOffset, $occurrences));
        self::assertSame(['router', 'utf8'], array_map(static fn (PhpConfigurationOccurrence $occurrence): string => substr($text, $occurrence->startOffset, $occurrence->endOffset - $occurrence->startOffset), $occurrences));
    }

    public function testResolvesConfigurationRootsFromAliasedBuilderImports(): void
    {
        $analyzer = new PhpConfigurationAnalyzer(new TolerantPhpParser(new Parser()), new PhpCommentParser());
        $index = $this->index();
        $text = <<<'PHP'
            <?php
            use Symfony\Config\FrameworkConfig as WebConfig;
            use Symfony\Config\Psr3Config as FrameworkConfig;

            return static function (WebConfig $web, FrameworkConfig $misleading): void {
                $web->router()->utf8(true);
                $misleading->enabled(true);
            };
            PHP;

        self::assertSame(
            [['framework', 'router'], ['framework', 'router', 'utf8'], ['psr_3', 'enabled']],
            array_map(static fn (PhpConfigurationOccurrence $occurrence): array => $occurrence->path, $analyzer->occurrences($text, $index)),
        );
    }

    public function testAnalyzesXmlElementsAttributesAndIncompleteTags(): void
    {
        $analyzer = new XmlConfigurationAnalyzer(new XmlCommentParser());
        $index = $this->index();
        $text = '<container><framework:config><framework:router utf8="true"><framework:utf8/></framework:router></framework:config></container>';

        $events = $analyzer->events($text, $index);
        self::assertInstanceOf(XmlConfigurationOccurrence::class, $events[2]);
        self::assertSame(['framework', 'router'], $events[2]->path);
        self::assertSame('utf8', $events[2]->attributes[0]->name);
        self::assertSame('true', $events[2]->attributes[0]->value);
        self::assertSame(strpos($text, 'utf8="true"'), $events[2]->attributes[0]->startOffset);
        self::assertSame(strpos($text, 'utf8="true"') + \strlen('utf8'), $events[2]->attributes[0]->endOffset);
        self::assertSame(['framework', 'router', 'utf8'], $analyzer->resolveNode($text, $index, strpos($text, 'framework:utf8') + 11)[0] ?? null);

        $malformed = '<framework:config>';
        $error = $analyzer->events($malformed, $index)[1];
        self::assertInstanceOf(XmlConfigurationStructureError::class, $error);
        self::assertSame('Element "framework:config" is not closed.', $error->message);
        self::assertSame(\strlen($malformed), $error->startOffset);
        self::assertSame(\strlen($malformed), $error->endOffset);

        $incomplete = '<container><framework:config><framework:router ut';
        self::assertSame(
            ['path' => ['framework', 'router'], 'prefix' => 'ut', 'start' => \strlen($incomplete) - 2, 'alias' => '', 'attribute' => true],
            $analyzer->completionContext($incomplete, $index, \strlen($incomplete)),
        );
    }

    /** @param list<int> $lines */
    #[DataProvider('yamlTabIndentationProvider')]
    public function testReportsTabsOnlyInYamlStructuralIndentation(string $text, array $lines): void
    {
        $analyzer = new YamlIndentationAnalyzer(new PositionConverter(), new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())));

        self::assertSame($lines, array_map(static fn (Range $range): int => $range->start->line, $analyzer->tabIndentedLines($text)));
    }

    /** @return iterable<string, array{string, list<int>}> */
    public static function yamlTabIndentationProvider(): iterable
    {
        yield 'tabbed mapping key' => ["parameters:\n\tapp.name: Demo\n", [1]];
        yield 'tab after leading spaces' => ["parameters:\n  \tapp.name: Demo\n", [1]];
        yield 'tabbed sequence item' => ["parameters:\n    app.list:\n\t- one\n", [2]];
        yield 'block literal content' => ["parameters:\n    app.script: |\n        all:\n        \techo hi\n", []];
        yield 'block folded content' => ["parameters:\n    app.note: >\n        first\n        \tsecond\n", []];
        yield 'mapping key after a block scalar' => ["parameters:\n    app.script: |\n        line\n\tapp.name: Demo\n", [3]];
        yield 'quoted continuation' => ["app.msg: \"first\n\tsecond\"\n", []];
        yield 'quoted continuation at the mapping indentation' => ["parameters:\n    app.msg: \"first\n    \tsecond\"\n", []];
        yield 'plain continuation at the mapping indentation' => ["parameters:\n    app.msg: first\n    \tsecond\n", [2]];
        yield 'plain continuation below the mapping' => ["parameters:\n    app.msg: first\n        \tsecond\n", []];
        yield 'tabbed key separator and value' => ["parameters:\n    app.name:\tDemo\n    app.other: a\tb\n", []];
    }

    private function index(): ConfigurationIndex
    {
        $index = new ConfigurationIndex();
        $index->replace([
            'framework' => $this->node('framework', [
                $this->node('router', [
                    $this->node('utf8'),
                ]),
                $this->node('psr_3', [
                    $this->node('enabled'),
                ]),
            ]),
            'psr_3' => $this->node('psr_3', [
                $this->node('enabled'),
            ]),
        ]);

        return $index;
    }

    /** @param list<ConfigurationNode> $children */
    private function node(string $name, array $children = []): ConfigurationNode
    {
        return new ConfigurationNode($name, [] === $children ? 'boolean' : 'array', false, false, null, null, null, false, [], [], $children, null);
    }
}
