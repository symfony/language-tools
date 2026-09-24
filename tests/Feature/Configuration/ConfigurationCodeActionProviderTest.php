<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Configuration\ConfigurationCodeActionProvider;
use Symfony\Lsp\Feature\Configuration\ConfigurationIndexRegistry;
use Symfony\Lsp\Feature\Configuration\ConfigurationNode;
use Symfony\Lsp\Feature\Configuration\PhpConfigurationAnalyzer;
use Symfony\Lsp\Feature\Configuration\XmlConfigurationAnalyzer;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\UnknownNameCodeActionBuilder;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Xml\TolerantXmlParser;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Tests\Support\ProjectPaths;

final class ConfigurationCodeActionProviderTest extends TestCase
{
    #[DataProvider('configurationTypoProvider')]
    public function testSuggestsOnlySiblingsWithSyntaxPreservingEdits(string $language, string $uri, string $text, string $diagnosed, string $replacement, string $edited, bool $suggestion): void
    {
        $documents = new DocumentStore();
        $documents->open(new Document($uri, $language, 3, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $index = new ConfigurationIndexRegistry();
        $router = $this->node('router', [$this->node('utf8')]);
        $root = $this->node('framework', [$router, $this->node('cache', [$this->node('strict')])]);
        $index->forProject($project)->replace(['framework' => $root]);
        $converter = new PositionConverter();
        $protocol = new LspProtocolMapper();
        $xmlParser = new TolerantXmlParser();
        $runtime = new RuntimeConfiguration();
        $runtime->configure(['environment' => 'dev']);
        $provider = new ConfigurationCodeActionProvider(
            new DocumentContextResolver($documents, $projects),
            ProjectPaths::resolver(),
            $converter,
            $protocol,
            $index,
            new RouteIndexRegistry(),
            $runtime,
            new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
            new PhpConfigurationAnalyzer(new TolerantPhpParser(new Parser()), new PhpCommentParser()),
            new XmlConfigurationAnalyzer($xmlParser, new XmlCommentParser($xmlParser)),
            new UnknownNameCodeActionBuilder($protocol),
        );
        $start = (int) strpos($text, $diagnosed);
        $diagnostic = $protocol->diagnostic($converter->toRange($text, $start, \strlen($diagnosed)), 1, 'config.unknown_key', 'Unknown configuration key.');
        $actions = $provider->actions(['textDocument' => ['uri' => $uri], 'context' => ['diagnostics' => [$diagnostic]]]);

        if (!$suggestion) {
            self::assertSame([], $actions ?? []);

            return;
        }
        $editedStart = (int) strpos($text, $edited);
        $edits = [['range' => $protocol->textEdit($converter->toRange($text, $editedStart, \strlen($edited)), $replacement)['range'], 'newText' => $replacement]];
        if (str_contains($text, '</framework:route>')) {
            $endStart = (int) strrpos($text, '</framework:route>') + \strlen('</framework:');
            $edits[] = ['range' => $protocol->textEdit($converter->toRange($text, $endStart, \strlen($edited)), $replacement)['range'], 'newText' => $replacement];
        }
        self::assertSame([[
            'title' => \sprintf('Replace with "%s"', $replacement),
            'kind' => 'quickfix',
            'diagnostics' => [$diagnostic],
            'isPreferred' => true,
            'edit' => ['documentChanges' => [[
                'textDocument' => ['uri' => $uri, 'version' => 3],
                'edits' => $edits,
            ]]],
        ]], $actions);
    }

    /** @return iterable<string, array{string, string, string, string, string, string, bool}> */
    public static function configurationTypoProvider(): iterable
    {
        yield 'yaml child' => ['yaml', 'file:///workspace/config/packages/framework.yaml', "framework:\n  router:\n    ut8: true\n", 'ut8', 'utf8', 'ut8', true];
        yield 'quoted yaml child' => ['yaml', 'file:///workspace/config/packages/framework.yaml', "framework:\n  router:\n    'ut8': true\n", 'ut8', 'utf8', 'ut8', true];
        yield 'php method' => ['php', 'file:///workspace/config/packages/framework.php', '<?php $framework->router()->ut8(true);', 'ut8', 'utf8', 'ut8', true];
        yield 'xml attribute' => ['xml', 'file:///workspace/config/packages/framework.xml', '<container><framework:config><framework:router ut8="true"/></framework:config></container>', 'ut8', 'utf8', 'ut8', true];
        yield 'xml element' => ['xml', 'file:///workspace/config/packages/framework.xml', '<container><framework:config><framework:route/></framework:config></container>', 'framework:route', 'router', 'route', true];
        yield 'xml paired element' => ['xml', 'file:///workspace/config/packages/framework.xml', '<container><framework:config><framework:route></framework:route></framework:config></container>', 'framework:route', 'router', 'route', true];
        yield 'xml unmatched element' => ['xml', 'file:///workspace/config/packages/framework.xml', '<container><framework:config><framework:route></framework:router></framework:config></container>', 'framework:route', 'router', 'route', false];
        yield 'xml unclosed element' => ['xml', 'file:///workspace/config/packages/framework.xml', '<container><framework:config><framework:route>', 'framework:route', 'router', 'route', false];
        yield 'unrelated sibling' => ['yaml', 'file:///workspace/config/packages/framework.yaml', "framework:\n  cache:\n    ut8: true\n", 'ut8', 'utf8', 'ut8', false];
        yield 'other environment' => ['yaml', 'file:///workspace/config/packages/framework.yaml', "when@test:\n  framework:\n    router:\n      ut8: true\n", 'ut8', 'utf8', 'ut8', false];
        yield 'route configuration' => ['yaml', 'file:///workspace/config/routes.yaml', "framework:\n  router:\n    ut8: true\n", 'ut8', 'utf8', 'ut8', false];
    }

    /** @param list<ConfigurationNode> $children */
    private function node(string $name, array $children = []): ConfigurationNode
    {
        return new ConfigurationNode($name, 'array', false, false, null, null, null, false, [], [], $children, null);
    }
}
