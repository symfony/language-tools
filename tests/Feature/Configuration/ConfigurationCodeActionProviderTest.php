<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Configuration\ConfigurationCodeActionProvider;
use Symfony\Lsp\Project\AnalysisSettingsRegistry;
use Symfony\Lsp\Project\ProjectAnalysisSettings;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class ConfigurationCodeActionProviderTest extends TestCase
{
    #[DataProvider('configurationTypoProvider')]
    public function testSuggestsOnlySiblingsWithSyntaxPreservingEdits(string $language, string $uri, string $text, string $diagnosed, string $replacement, string $edited, bool $suggestion): void
    {
        $router = $this->node('router', [$this->node('utf8')]);
        $root = $this->node('framework', [$router, $this->node('cache', [$this->node('strict')])]);
        $kit = (new ProjectTestKit())
            ->open($uri, $text, $language, 3)
            ->runtime('configuration', ['bundles' => [['alias' => 'framework', 'tree' => $root]]])
        ;
        $kit->get(AnalysisSettingsRegistry::class)->configureWorkspace(new ProjectAnalysisSettings(environment: 'dev'));
        $converter = $kit->get(PositionConverter::class);
        $protocol = $kit->get(LspProtocolMapper::class);
        $start = (int) strpos($text, $diagnosed);
        $diagnostic = $protocol->diagnostic($converter->toRange($text, $start, \strlen($diagnosed)), 1, 'config.unknown_key', 'Unknown configuration key.');
        $actions = $kit->get(ConfigurationCodeActionProvider::class)->actions($kit->codeAction($uri, [$diagnostic]));

        if (!$suggestion) {
            self::assertSame([], $actions);

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

    /**
     * @param list<array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private function node(string $name, array $children = []): array
    {
        return ['name' => $name, 'type' => 'array', 'children' => $children];
    }
}
