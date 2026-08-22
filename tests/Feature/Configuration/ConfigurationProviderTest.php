<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Configuration\ConfigurationCompletionProvider;
use Symfony\Lsp\Feature\Configuration\ConfigurationDiagnosticProvider;
use Symfony\Lsp\Feature\Configuration\ConfigurationDocumentLinkProvider;
use Symfony\Lsp\Feature\Configuration\ConfigurationHoverProvider;
use Symfony\Lsp\Feature\Configuration\ConfigurationIndexRegistry;
use Symfony\Lsp\Feature\Configuration\ConfigurationPathResolver;
use Symfony\Lsp\Feature\Configuration\ConfigurationValueValidator;
use Symfony\Lsp\Feature\Configuration\ProjectConfigurationSnapshotLoader;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;

final class ConfigurationProviderTest extends TestCase
{
    public function testYamlCompletionHoverDiagnosticsAndImportLinks(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = "imports:\n    - { resource: ../shared.yaml }\nwhen@test:\n    framework:\n        rou";
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));
        $position = $fixture->converter->toPosition($text, \strlen($text));
        $params = ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]];

        self::assertSame(['router'], array_column($fixture->completion->complete($params) ?? [], 'label'));
        self::assertSame('file:///workspace/config/shared.yaml', $fixture->links->links(['textDocument' => ['uri' => $uri]])[0]['target'] ?? null);

        $text = "framework:\n    router:\n        utf8: maybe\n        mode: old\n        unknown: true\n    router: {}";
        $fixture->documents->update($uri, 2, $text);
        $hoverOffset = strpos($text, 'utf8') + 2;
        $hoverPosition = $fixture->converter->toPosition($text, $hoverOffset);
        /** @var array{contents: array{value: string}} $hover */
        $hover = $fixture->hover->hover(['textDocument' => ['uri' => $uri], 'position' => ['line' => $hoverPosition->line(), 'character' => $hoverPosition->character()]]);
        self::assertStringContainsString('framework.router.utf8', $hover['contents']['value']);
        self::assertSame(['config.invalid_type', 'config.deprecated_key', 'config.invalid_type', 'config.unknown_key', 'config.duplicate_key'], array_column($fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));
    }

    public function testAcceptsNormalizedShorthandValuesAndUnverifiableKeys(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = "framework:\n    assets: ~\n    loose:\n        anything: 1\n        known: true\n    dispatch:\n        App\\Message\\OrderPlaced: async\n";
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testSkipsDiagnosticsOutsideTheApplicationConfiguration(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/src/AcmeBundle/test/app/config/config.yml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, "framework:\n    mystery: true\n"));

        self::assertNull($fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testStillRejectsScalarsForStrictArrayNodesAndUnknownKeys(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = "framework:\n    router: maybe\n    assets: some-string\n    mystery: true\n";
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        self::assertSame(
            ['config.invalid_type', 'config.invalid_type', 'config.unknown_key'],
            array_column($fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'),
        );
    }

    public function testDoesNotTreatDependencyInjectionSectionsAsBundleConfiguration(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/services.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, "parameters:\n    app.name: Demo\nservices:\n    _defaults:\n        autowire: true\n    App\\:\n        resource: ../src/\n"));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testDoesNotTreatEnvironmentOverridesAsDuplicates(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, "framework:\n    router:\n        utf8: true\nwhen@test:\n    framework:\n        router:\n            utf8: false\n"));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testReportsIncompatibleEnvironmentProcessorTypes(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, "framework:\n    router:\n        utf8: '%env(json:ROUTER_CONFIG)%'\n"));

        self::assertSame(['env.incompatible_type'], array_column($fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));
    }

    public function testDoesNotReportDynamicOrCommentedValues(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, "framework:\n    router:\n        utf8: '%env(bool:ROUTER_UTF8)%'\n        strict: true # selected mode\n"));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testSupportsPrototypeSequenceItems(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/framework.yaml';
        $text = "framework:\n    items:\n        - name: true\n        - name: false\n        - na";
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));
        $position = $fixture->converter->toPosition($text, \strlen($text));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
        self::assertSame(['name'], array_column($fixture->completion->complete(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]]) ?? [], 'label'));
    }

    public function testReportsMissingRequiredChildren(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, "framework:\n    required_parent:\n"));

        self::assertSame(['config.missing_required_key'], array_column($fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));
    }

    public function testReportsPhpAndXmlDiagnosticsAndProvidesHover(): void
    {
        $fixture = $this->providers();
        $cases = [
            ['file:///workspace/config/framework.php', 'php', "<?php \$framework->router()->utf8('bad');", ['config.invalid_type'], 'utf8'],
            ['file:///workspace/config/framework.xml', 'xml', '<container><framework:config><framework:router utf8="bad" unknown="x"/></framework:config></container>', ['config.invalid_type', 'config.unknown_key'], 'framework:router'],
        ];
        foreach ($cases as [$uri, $language, $text, $diagnostics, $hovered]) {
            $fixture->documents->open(new Document($uri, $language, 1, $text));
            self::assertSame($diagnostics, array_column($fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));
            $position = $fixture->converter->toPosition($text, strpos($text, $hovered) + 1);
            self::assertIsArray($fixture->hover->hover(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]]));
            $fixture->documents->close($uri);
        }
    }

    public function testCompletesEnumValuesAndPhpAndXmlDsl(): void
    {
        $fixture = $this->providers();
        $cases = [
            ['file:///workspace/config/framework.yaml', 'yaml', "framework:\n    router:\n        mode: ", ['dev', 'prod']],
            ['file:///workspace/config/framework.php', 'php', '<?php $framework->router()->ut', ['utf8']],
            ['file:///workspace/config/framework-typed.php', 'php', '<?php function configure(FrameworkConfig $options) { $options->router()->ut', ['utf8']],
            ['file:///workspace/config/framework.xml', 'xml', '<container><framework:config><framework:ro', ['router']],
            ['file:///workspace/config/framework-attribute.xml', 'xml', '<container><framework:config><framework:router ut', ['utf8']],
        ];
        foreach ($cases as [$uri, $language, $text, $expected]) {
            $fixture->documents->open(new Document($uri, $language, 1, $text));
            $position = $fixture->converter->toPosition($text, \strlen($text));
            self::assertSame($expected, array_column($fixture->completion->complete(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line(), 'character' => $position->character()]]) ?? [], 'label'));
            $fixture->documents->close($uri);
        }
    }

    private function providers(): ConfigurationProviderFixture
    {
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $project = new Project('/workspace', 'file:///workspace', '^8.0');
        $projects->replace([$project]);
        $converter = new PositionConverter();
        $indexes = new ConfigurationIndexRegistry();
        $environmentIndexes = new EnvironmentIndexRegistry();
        $environmentIndexes->forProject($project)->replaceProcessors(['bool' => 'bool', 'json' => 'array']);
        (new ProjectConfigurationSnapshotLoader($indexes))->load($project, ['sections' => ['configuration' => ['bundles' => [
            [
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
                    $this->node('assets', 'array', accepts: ['null' => true, 'true' => true, 'false' => true, 'scalar' => false, 'unknownKeys' => false], children: [
                        $this->node('enabled', 'boolean'),
                    ]),
                    $this->node('loose', 'array', accepts: ['unknownKeys' => true], children: [
                        $this->node('known', 'boolean'),
                    ]),
                    $this->node('dispatch', 'array', prototype: $this->node('sender', 'array', accepts: ['scalar' => true], children: [
                        $this->node('senders', 'array'),
                    ])),
                ]),
            ],
            [
                'alias' => 'services',
                'tree' => $this->node('services', 'array'),
            ],
        ]]]]);
        $resolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $paths = new ConfigurationPathResolver();
        $yaml = new YamlConfigurationParser($converter, new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())));

        return new ConfigurationProviderFixture(
            new ConfigurationCompletionProvider($resolver, $converter, $protocol, $indexes, $paths, $yaml),
            new ConfigurationHoverProvider($resolver, $converter, $protocol, $indexes, $paths, $yaml),
            new ConfigurationDiagnosticProvider($resolver, $converter, $protocol, $indexes, $paths, $yaml, new ConfigurationValueValidator($environmentIndexes)),
            new ConfigurationDocumentLinkProvider($resolver, $converter, $protocol, new UriToPathConverter()),
            $documents,
            $converter,
        );
    }

    /**
     * @param array<array-key, array<array-key, mixed>> $children
     * @param list<string>                              $allowedValues
     * @param array<array-key, mixed>|null              $prototype
     * @param array<string, bool>                       $accepts
     *
     * @return array<array-key, mixed>
     */
    private function node(string $name, string $type, array $children = [], ?string $info = null, bool $deprecated = false, array $allowedValues = [], bool $required = false, ?array $prototype = null, array $accepts = []): array
    {
        return ['name' => $name, 'type' => $type, 'required' => $required, 'hasDefault' => false, 'defaultSummary' => null, 'info' => $info, 'example' => null, 'deprecated' => $deprecated, 'allowedValues' => $allowedValues, 'children' => $children, 'prototype' => $prototype, 'accepts' => $accepts];
    }
}

final class ConfigurationProviderFixture
{
    public function __construct(
        public readonly ConfigurationCompletionProvider $completion,
        public readonly ConfigurationHoverProvider $hover,
        public readonly ConfigurationDiagnosticProvider $diagnostics,
        public readonly ConfigurationDocumentLinkProvider $links,
        public readonly DocumentStore $documents,
        public readonly PositionConverter $converter,
    ) {
    }
}
