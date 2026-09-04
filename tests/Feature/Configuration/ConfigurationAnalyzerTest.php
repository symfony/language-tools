<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Configuration\ConfigurationIndex;
use Symfony\Lsp\Feature\Configuration\ConfigurationNode;
use Symfony\Lsp\Feature\Configuration\PhpConfigurationAnalyzer;
use Symfony\Lsp\Feature\Configuration\PhpConfigurationOccurrence;
use Symfony\Lsp\Feature\Configuration\XmlConfigurationAnalyzer;
use Symfony\Lsp\Feature\Configuration\XmlConfigurationOccurrence;
use Symfony\Lsp\Feature\Configuration\XmlConfigurationStructureError;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;

final class ConfigurationAnalyzerTest extends TestCase
{
    public function testAnalyzesCompleteAndIncompletePhpDslChains(): void
    {
        $analyzer = new PhpConfigurationAnalyzer(new TolerantPhpParser(new Parser()), new PhpCommentParser());
        $index = $this->index();
        $text = '<?php function configure(FrameworkConfig $options) { $options->router()->utf8(true); }';

        self::assertSame([
            ['path' => ['framework', 'router'], 'argument' => ''],
            ['path' => ['framework', 'router', 'utf8'], 'argument' => 'true'],
        ], array_map(static fn (PhpConfigurationOccurrence $occurrence): array => ['path' => $occurrence->path, 'argument' => $occurrence->argument->source], $analyzer->occurrences($text, $index)));
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
            [['path' => ['psr_3', 'enabled'], 'argument' => 'true']],
            array_map(static fn (PhpConfigurationOccurrence $occurrence): array => ['path' => $occurrence->path, 'argument' => $occurrence->argument->source], $analyzer->occurrences($rootWithDigit, $index)),
        );
        $incompleteRootWithDigit = '<?php function configure(Psr3Config $options) { $options->en';
        self::assertSame(
            ['path' => ['psr_3'], 'prefix' => 'en', 'start' => \strlen($incompleteRootWithDigit) - 2],
            $analyzer->completionContext($incompleteRootWithDigit, $index, \strlen($incompleteRootWithDigit)),
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
