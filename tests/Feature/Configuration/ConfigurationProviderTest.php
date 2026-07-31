<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Configuration\ConfigurationIndexRegistry;
use Symfony\Lsp\Feature\Configuration\ConfigurationProvider;
use Symfony\Lsp\Feature\Configuration\ProjectConfigurationSnapshotLoader;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class ConfigurationProviderTest extends TestCase
{
    public function testYamlCompletionHoverDiagnosticsAndImportLinks(): void
    {
        [$provider, $documents, $converter] = $this->provider();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = "imports:\n    - { resource: ../shared.yaml }\nwhen@test:\n    framework:\n        rou";
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $position = $converter->toPosition($text, \strlen($text));
        $params = ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]];

        self::assertSame(['router'], array_column($provider->complete($params) ?? [], 'label'));
        self::assertSame('file:///workspace/config/shared.yaml', $provider->links(['textDocument' => ['uri' => $uri]])[0]['target'] ?? null);

        $text = "framework:\n    router:\n        utf8: maybe\n        mode: old\n        unknown: true\n    router: {}";
        $documents->update($uri, 2, $text);
        $hoverOffset = strpos($text, 'utf8') + 2;
        $hoverPosition = $converter->toPosition($text, $hoverOffset);
        $hover = $provider->hover(['textDocument' => ['uri' => $uri], 'position' => ['line' => $hoverPosition->line(), 'character' => $hoverPosition->character()]]);
        self::assertIsArray($hover);
        self::assertIsArray($hover['contents'] ?? null);
        self::assertIsString($hover['contents']['value'] ?? null);
        self::assertStringContainsString('framework.router.utf8', $hover['contents']['value']);
        self::assertSame(['config.invalid_type', 'config.deprecated_key', 'config.invalid_type', 'config.unknown_key', 'config.duplicate_key'], array_column($provider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));
    }

    public function testDoesNotTreatEnvironmentOverridesAsDuplicates(): void
    {
        [$provider, $documents] = $this->provider();
        $uri = 'file:///workspace/config/framework.yaml';
        $documents->open(new Document($uri, 'yaml', 1, "framework:\n    router:\n        utf8: true\nwhen@test:\n    framework:\n        router:\n            utf8: false\n"));

        self::assertSame([], $provider->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testReportsIncompatibleEnvironmentProcessorTypes(): void
    {
        [$provider, $documents] = $this->provider();
        $uri = 'file:///workspace/config/framework.yaml';
        $documents->open(new Document($uri, 'yaml', 1, "framework:\n    router:\n        utf8: '%env(json:ROUTER_CONFIG)%'\n"));

        self::assertSame(['env.incompatible_type'], array_column($provider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));
    }

    public function testDoesNotReportDynamicOrCommentedValues(): void
    {
        [$provider, $documents] = $this->provider();
        $uri = 'file:///workspace/config/framework.yaml';
        $documents->open(new Document($uri, 'yaml', 1, "framework:\n    router:\n        utf8: '%env(bool:ROUTER_UTF8)%'\n        strict: true # selected mode\n"));

        self::assertSame([], $provider->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testSupportsPrototypeSequenceItems(): void
    {
        [$provider, $documents, $converter] = $this->provider();
        $uri = 'file:///workspace/config/framework.yaml';
        $text = "framework:\n    items:\n        - name: true\n        - name: false\n        - na";
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $position = $converter->toPosition($text, \strlen($text));

        self::assertSame([], $provider->diagnostics(['textDocument' => ['uri' => $uri]]));
        self::assertSame(['name'], array_column($provider->complete(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]]) ?? [], 'label'));
    }

    public function testReportsMissingRequiredChildren(): void
    {
        [$provider, $documents] = $this->provider();
        $uri = 'file:///workspace/config/framework.yaml';
        $documents->open(new Document($uri, 'yaml', 1, "framework:\n    required_parent:\n"));

        self::assertSame(['config.missing_required_key'], array_column($provider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));
    }

    public function testReportsPhpAndXmlDiagnosticsAndProvidesHover(): void
    {
        [$provider, $documents, $converter] = $this->provider();
        $cases = [
            ['file:///workspace/config/framework.php', 'php', "<?php \$framework->router()->utf8('bad');", ['config.invalid_type'], 'utf8'],
            ['file:///workspace/config/framework.xml', 'xml', '<container><framework:config><framework:router utf8="bad" unknown="x"/></framework:config></container>', ['config.invalid_type', 'config.unknown_key'], 'framework:router'],
        ];
        foreach ($cases as [$uri, $language, $text, $diagnostics, $hovered]) {
            $documents->open(new Document($uri, $language, 1, $text));
            self::assertSame($diagnostics, array_column($provider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));
            $position = $converter->toPosition($text, strpos($text, $hovered) + 1);
            self::assertNotNull($provider->hover(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]]));
            $documents->close($uri);
        }
    }

    public function testCompletesEnumValuesAndPhpAndXmlDsl(): void
    {
        [$provider, $documents, $converter] = $this->provider();
        $cases = [
            ['file:///workspace/config/framework.yaml', 'yaml', "framework:\n    router:\n        mode: ", ['dev', 'prod']],
            ['file:///workspace/config/framework.php', 'php', '<?php $framework->router()->ut', ['utf8']],
            ['file:///workspace/config/framework.xml', 'xml', '<container><framework:config><framework:ro', ['router']],
            ['file:///workspace/config/framework-attribute.xml', 'xml', '<container><framework:config><framework:router ut', ['utf8']],
        ];
        foreach ($cases as [$uri, $language, $text, $expected]) {
            $documents->open(new Document($uri, $language, 1, $text));
            $position = $converter->toPosition($text, \strlen($text));
            self::assertSame($expected, array_column($provider->complete(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]]) ?? [], 'label'));
            $documents->close($uri);
        }
    }

    /** @return array{ConfigurationProvider, DocumentStore, PositionConverter} */
    private function provider(): array
    {
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects->replace([$project]);
        $converter = new PositionConverter();
        $indexes = new ConfigurationIndexRegistry();
        $environmentIndexes = new EnvironmentIndexRegistry();
        $environmentIndexes->forProject($project)->replaceProcessors(['bool' => 'bool', 'json' => 'array']);
        (new ProjectConfigurationSnapshotLoader($indexes))->load($project, ['sections' => ['configuration' => ['bundles' => [[
            'alias' => 'framework',
            'tree' => $this->node('framework', 'array', children: [
                $this->node('router', 'array', children: [
                    $this->node('utf8', 'boolean', info: 'Use UTF-8 routes.'),
                    $this->node('strict', 'boolean'),
                    $this->node('mode', 'enum', deprecated: true, allowedValues: ['dev', 'prod']),
                ]),
                $this->node('required_parent', 'array', children: [
                    $this->node('token', 'scalar', required: true),
                ]),
                $this->node('items', 'array', prototype: $this->node('item', 'array', children: [
                    $this->node('name', 'boolean'),
                ])),
            ]),
        ]]]]]);

        return [new ConfigurationProvider(new DocumentContextResolver($documents, $projects), $documents, $projects, $converter, $indexes, new YamlConfigurationParser($converter), $environmentIndexes), $documents, $converter];
    }

    /**
     * @param array<array-key, array<array-key, mixed>> $children
     * @param list<string>                              $allowedValues
     * @param array<array-key, mixed>|null              $prototype
     *
     * @return array<array-key, mixed>
     */
    private function node(string $name, string $type, array $children = [], ?string $info = null, bool $deprecated = false, array $allowedValues = [], bool $required = false, ?array $prototype = null): array
    {
        return ['name' => $name, 'type' => $type, 'required' => $required, 'hasDefault' => false, 'defaultSummary' => null, 'info' => $info, 'example' => null, 'deprecated' => $deprecated, 'allowedValues' => $allowedValues, 'children' => $children, 'prototype' => $prototype];
    }
}
