<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Configuration\ConfigurationIndex;
use Symfony\Lsp\Feature\Configuration\ConfigurationNode;
use Symfony\Lsp\Feature\Configuration\PhpConfigurationAnalyzer;
use Symfony\Lsp\Feature\Configuration\XmlConfigurationAnalyzer;
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
        ], array_map(static fn (array $occurrence): array => ['path' => $occurrence['path'], 'argument' => $occurrence['argument']], $analyzer->occurrences($text, $index)));
        self::assertSame(['framework', 'router', 'utf8'], $analyzer->resolveNode($text, $index, strpos($text, 'utf8') + 1)[0] ?? null);

        $incomplete = '<?php function configure(FrameworkConfig $options) { $options->router()->ut';
        self::assertSame(
            ['path' => ['framework', 'router'], 'prefix' => 'ut', 'start' => \strlen($incomplete) - 2],
            $analyzer->completionContext($incomplete, \strlen($incomplete)),
        );
    }

    public function testAnalyzesXmlElementsAttributesAndIncompleteTags(): void
    {
        $analyzer = new XmlConfigurationAnalyzer(new XmlCommentParser());
        $index = $this->index();
        $text = '<container><framework:config><framework:router><framework:utf8/></framework:router></framework:config></container>';

        self::assertSame(['framework', 'router', 'utf8'], $analyzer->resolveNode($text, $index, strpos($text, 'framework:utf8') + 11)[0] ?? null);

        $incomplete = '<container><framework:config><framework:router ut';
        self::assertSame(
            ['path' => ['framework', 'router'], 'prefix' => 'ut', 'start' => \strlen($incomplete) - 2, 'alias' => '', 'attribute' => true],
            $analyzer->completionContext($incomplete, $index, \strlen($incomplete)),
        );
    }

    private function index(): ConfigurationIndex
    {
        $index = new ConfigurationIndex();
        $index->replace(['framework' => $this->node('framework', [
            $this->node('router', [
                $this->node('utf8'),
            ]),
        ])]);

        return $index;
    }

    /** @param list<ConfigurationNode> $children */
    private function node(string $name, array $children = []): ConfigurationNode
    {
        return new ConfigurationNode($name, [] === $children ? 'boolean' : 'array', false, false, null, null, null, false, [], [], $children, null);
    }
}
