<?php

namespace Symfony\Lsp\Tests\Feature\Configuration;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Configuration\ConfigurationCompletionProvider;
use Symfony\Lsp\Feature\Configuration\ConfigurationDiagnosticProvider;
use Symfony\Lsp\Feature\Configuration\ConfigurationDocumentLinkProvider;
use Symfony\Lsp\Feature\Configuration\ConfigurationHoverProvider;
use Symfony\Lsp\Feature\Configuration\ConfigurationIndexRegistry;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationReconciler;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationRegistry;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationResult;
use Symfony\Lsp\Feature\Configuration\ConfigurationValueValidator;
use Symfony\Lsp\Feature\Configuration\PhpConfigurationAnalyzer;
use Symfony\Lsp\Feature\Configuration\ProjectConfigurationSnapshotLoader;
use Symfony\Lsp\Feature\Configuration\XmlConfigurationAnalyzer;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Feature\Environment\EnvironmentExpressionParser;
use Symfony\Lsp\Feature\Environment\EnvironmentIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Parser\Php\PhpCommentParser;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\SavedDocumentMatcher;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class ConfigurationProviderTest extends TestCase
{
    public function testYamlCompletionHoverDiagnosticsAndImportLinks(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = "imports:\n    - { resource: ../shared.yaml }\nwhen@test:\n    framework:\n        rou";
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));
        $position = $fixture->converter->toPosition($text, \strlen($text));
        $params = ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]];

        self::assertSame(['router'], array_column($fixture->completion->complete($params) ?? [], 'label'));
        self::assertSame('file:///workspace/config/shared.yaml', $fixture->links->links(['textDocument' => ['uri' => $uri]])[0]['target'] ?? null);

        $text = "framework:\n    router:\n        utf8: maybe\n        mode: old\n        unknown: true\n    router: {}";
        $fixture->documents->update($uri, 2, $text);
        $hoverOffset = strpos($text, 'utf8') + 2;
        $hoverPosition = $fixture->converter->toPosition($text, $hoverOffset);
        /** @var array{contents: array{value: string}} $hover */
        $hover = $fixture->hover->hover(['textDocument' => ['uri' => $uri], 'position' => ['line' => $hoverPosition->line, 'character' => $hoverPosition->character]]);
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

    public function testAcceptsBackedEnumScalarAndTaggedValues(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = <<<'YAML'
            framework:
                router:
                    reset_mode: schema
            when@dev:
                framework:
                    router:
                        reset_mode: !php/enum App\ResetMode::SCHEMA
            YAML;
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));

        $fixture->documents->update($uri, 2, str_replace('::SCHEMA', '::UNKNOWN', $text));
        self::assertSame(
            ['config.invalid_type'],
            array_column($fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]), 'code'),
        );
    }

    public function testCompletesAndValidatesPureEnumCases(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = "framework:\n    router:\n        strict_reset_mode: ";
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));
        $position = $fixture->converter->toPosition($text, \strlen($text));
        $params = ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]];

        self::assertSame(
            ['!php/enum App\\ResetMode::SCHEMA', '!php/enum App\\ResetMode::MIGRATE'],
            array_column($fixture->completion->complete($params) ?? [], 'label'),
        );

        $text .= '!php/enum App\\ResetMode::SCHEMA';
        $fixture->documents->update($uri, 2, $text);
        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
        $hoverPosition = $fixture->converter->toPosition($text, (int) strpos($text, 'strict_reset_mode') + 2);
        /** @var array{contents: array{value: string}} $hover */
        $hover = $fixture->hover->hover(['textDocument' => ['uri' => $uri], 'position' => ['line' => $hoverPosition->line, 'character' => $hoverPosition->character]]);
        self::assertStringContainsString('!php/enum App\\ResetMode::SCHEMA', $hover['contents']['value']);

        $fixture->documents->update($uri, 3, str_replace('::SCHEMA', '::UNKNOWN', $text));
        self::assertSame(
            ['config.invalid_type'],
            array_column($fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]), 'code'),
        );
    }

    public function testResolvesNestedSequencePrototypes(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = <<<'YAML'
            framework:
                items:
                    first:
                        handlers:
                            - type: stream
                              nested: true
                        policies:
                            - default-src: true
            YAML;
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));

        $text .= "\n                - typo: true";
        $fixture->documents->update($uri, 2, $text);
        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]);
        self::assertSame(
            ['Unknown configuration key "framework.items.first.policies.typo".'],
            array_column($diagnostics, 'message'),
        );
    }

    public function testHonorsConfigurationKeyNormalization(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = <<<'YAML'
            framework:
                normalized-section:
                    nested-key: true
                    mixed-nested_key: true
                    twin-key: true
                    twin_key: true
                exact_keys:
                    default-src: true
                    default_src: true
                exact_items:
                    - default-src: true
                      default_src: true
            YAML;
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.unknown_key', 'config.unknown_key', 'config.unknown_key', 'config.unknown_key'], array_column($diagnostics, 'code'));
        self::assertSame(
            [
                'Unknown configuration key "framework.normalized_section.mixed-nested_key".',
                'Unknown configuration key "framework.normalized_section.twin-key".',
                'Unknown configuration key "framework.exact_keys.default_src".',
                'Unknown configuration key "framework.exact_items.default_src".',
            ],
            array_column($diagnostics, 'message'),
        );

        foreach ([
            ['nested-key', 'framework.normalized_section.nested_key'],
            ['default-src', 'framework.exact_keys.default-src'],
        ] as [$key, $expectedPath]) {
            $position = $fixture->converter->toPosition($text, strpos($text, $key) + 2);
            $hover = $fixture->hover->hover([
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line, 'character' => $position->character],
            ]);
            self::assertIsArray($hover);
            self::assertIsArray($hover['contents'] ?? null);
            self::assertIsString($hover['contents']['value'] ?? null);
            self::assertStringContainsString($expectedPath, $hover['contents']['value']);
        }

        foreach ([
            ['exact_keys', 'def', ['default-src']],
            ['normalized_section', 'nes', ['nested_key']],
        ] as [$parent, $prefix, $expected]) {
            $text = "framework:\n    {$parent}:\n        {$prefix}";
            $fixture->documents->update($uri, 2, $text);
            $position = $fixture->converter->toPosition($text, \strlen($text));
            self::assertSame($expected, array_column($fixture->completion->complete([
                'textDocument' => ['uri' => $uri],
                'position' => ['line' => $position->line, 'character' => $position->character],
            ]) ?? [], 'label'));
        }
    }

    public function testValidatesKnownChildrenOfNodesThatAcceptUnknownKeys(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, "framework:\n    loose:\n        strict:\n            typo: true\n"));

        self::assertSame(['config.unknown_key'], array_column($fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));
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

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(
            ['config.invalid_type', 'config.invalid_type', 'config.unknown_key'],
            array_column($diagnostics, 'code'),
        );
        self::assertSame([2, 2, 2], array_column($diagnostics, 'severity'));
    }

    public function testDoesNotTreatDependencyInjectionSectionsAsBundleConfiguration(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/services.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, "parameters:\n    app.name: Demo\nservices:\n    _defaults:\n        autowire: true\n    App\\:\n        resource: ../src/\n"));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testSkipsConventionalRouteFilesAndResourcesLoadedByTheRouter(): void
    {
        $fixture = $this->providers();
        foreach (['config/routes/sulu_website.yaml', 'config/routes.yaml', 'config/http_endpoints.yaml'] as $path) {
            $uri = 'file:///workspace/'.$path;
            $fixture->documents->open(new Document($uri, 'yaml', 1, "framework:\n    resource: routes.yaml\n"));

            self::assertNull($fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
            $fixture->documents->close($uri);
        }

        $uri = 'file:///workspace/config/packages/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, "framework:\n    resource: routes.yaml\n"));

        self::assertSame(['config.unknown_key'], array_column($fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'));
    }

    public function testIgnoresInactiveEnvironmentSemantics(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, "when@test:\n    framework:\n        mystery: true\n"));

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
        self::assertSame(['name'], array_column($fixture->completion->complete(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]]) ?? [], 'label'));
    }

    public function testDoesNotDiagnoseRequiredChildrenBeforeConfigurationIsMerged(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, "framework:\n    required_parent:\n        known: true\nwhen@test:\n    framework:\n        required_parent:\n            known: false\n"));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testAcceptsAliasesAndKeyedPrototypeEntryNames(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, <<<'YAML'
            framework:
                cache:
                    pools:
                        valid:
                            adapter: cache.app
                        invalid:
                            typo: cache.app
            monolog:
                handlers:
                    nested:
                        type: stream
                        path: php://stderr
                        level: debug
                        typo: true
            YAML));

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.unknown_key', 'config.unknown_key'], array_column($diagnostics, 'code'));
        self::assertSame([
            'Unknown configuration key "framework.cache.pools.invalid.typo".',
            'Unknown configuration key "monolog.handlers.nested.typo".',
        ], array_column($diagnostics, 'message'));
    }

    public function testAcceptsYamlAliasesAndMergeKeys(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/security.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, <<<'YAML'
            security:
                firewalls:
                    a:
                        pattern: ^/a
                        remember_me: &remember_me
                            secret: '%kernel.secret%'
                            name: auth_token
                    b:
                        pattern: ^/b
                        remember_me: *remember_me
                    c:
                        pattern: ^/c
                        remember_me:
                            <<: *remember_me
                            always_remember_me: true
            YAML));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testValidatesYamlMergeKeysInsidePrototypeSequences(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = <<<'YAML'
            defaults: &defaults
                handlers:
                    - type: stream
                      nested: invalid
            framework:
                items:
                    - <<: *defaults
            YAML;
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.invalid_type'], array_column($diagnostics, 'code'));
        self::assertSame(['Expected boolean for "framework.items.handlers.nested".'], array_column($diagnostics, 'message'));
        self::assertSame(
            [$this->protocolRange($fixture->converter, $text, (int) strpos($text, '<<'), 2)],
            array_column($diagnostics, 'range'),
        );
    }

    public function testValidatesYamlMappingAliasesInsidePrototypeSequences(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = <<<'YAML'
            handlers: &handlers
                - type: stream
                  nested: invalid
            framework:
                items:
                    - handlers: *handlers
            YAML;
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.invalid_type'], array_column($diagnostics, 'code'));
        self::assertSame(['Expected boolean for "framework.items.handlers.nested".'], array_column($diagnostics, 'message'));
        self::assertSame(
            [$this->protocolRange($fixture->converter, $text, (int) strpos($text, '*handlers'), \strlen('*handlers'))],
            array_column($diagnostics, 'range'),
        );
    }

    public function testValidatesYamlListAliasesAtPrototypeNodes(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = <<<'YAML'
            items: &items
                - handlers:
                    - type: stream
                      nested: true
                - handlers:
                    - type: stream
                      nested: invalid
            framework:
                items: *items
            YAML;
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.invalid_type'], array_column($diagnostics, 'code'));
        self::assertSame(['Expected boolean for "framework.items.handlers.nested".'], array_column($diagnostics, 'message'));
        self::assertSame(
            [$this->protocolRange($fixture->converter, $text, (int) strpos($text, '*items'), \strlen('*items'))],
            array_column($diagnostics, 'range'),
        );
    }

    public function testValidatesResolvedYamlAliasValues(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = <<<'YAML'
            quoted: &quoted 'true'
            framework:
                router:
                    utf8: &enabled true
                    strict: *enabled
                required_parent:
                    known: *quoted
                    token: present
            YAML;
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.invalid_type'], array_column($diagnostics, 'code'));
        self::assertSame(
            [$this->protocolRange($fixture->converter, $text, (int) strpos($text, '*quoted'), \strlen('*quoted'))],
            array_column($diagnostics, 'range'),
        );
    }

    public function testValidatesYamlEnumTagsInheritedFromAliases(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = <<<'YAML'
            valid: &valid
                reset_mode: !php/enum App\ResetMode::SCHEMA
            invalid: &invalid
                reset_mode: !php/enum App\ResetMode::UNKNOWN
            framework:
                router:
                    <<: *invalid
            when@dev:
                framework:
                    router: *valid
            YAML;
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.invalid_type'], array_column($diagnostics, 'code'));
        self::assertSame(
            [$this->protocolRange($fixture->converter, $text, (int) strpos($text, '<<'), 2)],
            array_column($diagnostics, 'range'),
        );
    }

    public function testKeepsPhpConstantsOpaqueDirectlyAndWhileResolvingAliases(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, <<<'YAML'
            defaults: &defaults
                strict: !php/const PHP_VERSION_ID
            framework:
                router:
                    <<: *defaults
                    utf8: !php/const PHP_VERSION_ID
            YAML));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testDirectAliasDiagnosticsKeepTheAliasRangeAlongsideMergeKeys(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = <<<'YAML'
            base: &base
                assets:
                    enabled: true
            inner: &inner
                mystery: true
            framework:
                <<: *base
                router: *inner
            YAML;
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.unknown_key'], array_column($diagnostics, 'code'));
        self::assertSame(
            [$this->protocolRange($fixture->converter, $text, (int) strpos($text, '*inner'), \strlen('*inner'))],
            array_column($diagnostics, 'range'),
        );
    }

    public function testExplicitYamlKeysOverrideMergedValues(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, <<<'YAML'
            defaults: &defaults
                strict: invalid
            framework:
                router:
                    <<: *defaults
                    strict: true
            YAML));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testValidatesConfigurationInheritedFromYamlAliases(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = <<<'YAML'
            defaults: &defaults
                mystery: true
                strict: invalid
            framework:
                router:
                    <<: *defaults
                assets: *defaults
            YAML;
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.unknown_key', 'config.invalid_type', 'config.unknown_key', 'config.unknown_key'], array_column($diagnostics, 'code'));
        self::assertSame([
            'Unknown configuration key "framework.router.mystery".',
            'Expected boolean for "framework.router.strict".',
            'Unknown configuration key "framework.assets.mystery".',
            'Unknown configuration key "framework.assets.strict".',
        ], array_column($diagnostics, 'message'));
        $mergeRange = $this->protocolRange($fixture->converter, $text, (int) strpos($text, '<<'), 2);
        $aliasRange = $this->protocolRange($fixture->converter, $text, (int) strrpos($text, '*defaults'), \strlen('*defaults'));
        self::assertSame([$mergeRange, $mergeRange, $aliasRange, $aliasRange], array_column($diagnostics, 'range'));

        $fixture->documents->update($uri, 2, $text."\nbroken: [");
        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testAcceptsKeyAttributesInsideMappedPrototypeEntries(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/security.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, <<<'YAML'
            security:
                password_hashers:
                    user:
                        class: App\Model\User
                        algorithm: bcrypt
                        typo: true
            YAML));

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.unknown_key'], array_column($diagnostics, 'code'));
        self::assertSame([2], array_column($diagnostics, 'severity'));
    }

    public function testSupportsKeyedPrototypeSequenceItems(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/monolog.yaml';
        $text = <<<'YAML'
            framework:
                items:
                    - name: true
                      handlers:
                          nested:
                              type: stream
            monolog:
                handlers:
                    - name: nested
                      type: stream
                      path: php://stderr
                      level:
            YAML;
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        self::assertSame([], $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]));
        $position = $fixture->converter->toPosition($text, \strlen($text));
        self::assertSame(['debug', 'info'], array_column($fixture->completion->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]) ?? [], 'label'));
    }

    public function testComparesEnumValuesWithoutLosingTheirTypes(): void
    {
        $fixture = $this->providers(environment: 'test');
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $fixture->documents->open(new Document($uri, 'yaml', 1, <<<'YAML'
            framework:
                session:
                    cookie_secure: auto
            when@prod:
                framework:
                    session:
                        cookie_secure: true
            when@test:
                framework:
                    session:
                        cookie_secure: 'true'
            YAML));

        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.invalid_type'], array_column($diagnostics, 'code'));

        $text = "framework:\n    session:\n        cookie_secure: ";
        $fixture->documents->update($uri, 2, $text);
        $position = $fixture->converter->toPosition($text, \strlen($text));
        self::assertSame(['true', 'false', 'auto'], array_column($fixture->completion->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]) ?? [], 'label'));
        $position = $fixture->converter->toPosition($text, strpos($text, 'cookie_secure') + 2);
        $hover = $fixture->hover->hover([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]);
        self::assertIsArray($hover);
        self::assertIsArray($hover['contents'] ?? null);
        self::assertIsString($hover['contents']['value'] ?? null);
        self::assertStringContainsString('Allowed values: `true`, `false`, `auto`', $hover['contents']['value']);

        $fixture->documents->close($uri);
        $uri = 'file:///workspace/config/framework.php';
        $text = '<?php $framework->session()->cookieS';
        $fixture->documents->open(new Document($uri, 'php', 1, $text));
        $position = $fixture->converter->toPosition($text, \strlen($text));
        $completion = $fixture->completion->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line, 'character' => $position->character],
        ]);
        self::assertIsArray($completion);
        self::assertIsArray($completion[0] ?? null);
        self::assertIsArray($completion[0]['textEdit'] ?? null);
        self::assertSame('cookieSecure(${1:true})', $completion[0]['textEdit']['newText'] ?? null);
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
            self::assertIsArray($fixture->hover->hover(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]]));
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
            self::assertSame($expected, array_column($fixture->completion->complete(['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]]) ?? [], 'label'));
            $fixture->documents->close($uri);
        }
    }

    public function testIgnoresCommentedPhpConfigurationAcrossCapabilities(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/framework.php';
        $text = <<<'PHP'
            <?php
            // $framework->router()->utf8('bad');
            // $framework->router()->ut
            $framework->router()->utf8('bad');
            $framework->router()->utf8(true);
            $framework->router()->utf8(/* selected */ true);
            PHP;
        $fixture->documents->open(new Document($uri, 'php', 1, $text));

        $liveDiagnosticOffset = (int) strrpos($text, "utf8('bad')");
        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.invalid_type'], array_column($diagnostics, 'code'));
        self::assertSame($this->protocolRange($fixture->converter, $text, $liveDiagnosticOffset, \strlen('utf8')), $diagnostics[0]['range'] ?? null);

        $commentHoverOffset = strpos($text, 'utf8') + 1;
        $liveHoverOffset = strrpos($text, 'utf8') + 1;
        self::assertNull($fixture->hover->hover($this->positionParams($fixture->converter, $uri, $text, $commentHoverOffset)));
        self::assertIsArray($fixture->hover->hover($this->positionParams($fixture->converter, $uri, $text, $liveHoverOffset)));

        $commentCompletionOffset = strpos($text, '// $framework->router()->ut') + \strlen('// $framework->router()->ut');
        $liveCompletionStart = (int) strrpos($text, 'utf8');
        $liveCompletionOffset = $liveCompletionStart + \strlen('ut');
        self::assertNull($fixture->completion->complete($this->positionParams($fixture->converter, $uri, $text, $commentCompletionOffset)));
        $completion = $fixture->completion->complete($this->positionParams($fixture->converter, $uri, $text, $liveCompletionOffset)) ?? [];
        self::assertSame(['utf8'], array_column($completion, 'label'));
        /** @var array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} $textEdit */
        $textEdit = $completion[0]['textEdit'];
        self::assertSame($this->protocolRange($fixture->converter, $text, $liveCompletionStart, \strlen('ut')), $textEdit['range']);
    }

    public function testIgnoresCommentedXmlConfigurationAcrossCapabilities(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/framework.xml';
        $text = <<<'XML'
            <container>
                <framework:config>
                    <!-- "<ignored>" <framework:router utf8="bad" unknown="x"><framework:utf8/></framework:router> -->
                    <framework:router utf8="bad">
                        <!-- <framework:ut -->
                        <framework:utf8/>
                    </framework:router>
                </framework:config>
            </container>
            XML;
        $fixture->documents->open(new Document($uri, 'xml', 1, $text));

        $liveDiagnosticOffset = (int) strpos($text, 'utf8="bad"', (int) strpos($text, '-->'));
        $diagnostics = $fixture->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [];
        self::assertSame(['config.invalid_type'], array_column($diagnostics, 'code'));
        self::assertSame($this->protocolRange($fixture->converter, $text, $liveDiagnosticOffset, \strlen('utf8')), $diagnostics[0]['range'] ?? null);

        $commentHoverOffset = strpos($text, 'framework:utf8') + \strlen('framework:') + 1;
        $liveHoverOffset = strrpos($text, 'framework:utf8') + \strlen('framework:') + 1;
        self::assertNull($fixture->hover->hover($this->positionParams($fixture->converter, $uri, $text, $commentHoverOffset)));
        self::assertIsArray($fixture->hover->hover($this->positionParams($fixture->converter, $uri, $text, $liveHoverOffset)));

        $commentCompletionOffset = strpos($text, '<framework:ut', (int) strpos($text, '-->')) + \strlen('<framework:ut');
        $liveCompletionStart = strrpos($text, '<framework:utf8') + \strlen('<framework:');
        $liveCompletionOffset = $liveCompletionStart + \strlen('ut');
        self::assertNull($fixture->completion->complete($this->positionParams($fixture->converter, $uri, $text, $commentCompletionOffset)));
        $completion = $fixture->completion->complete($this->positionParams($fixture->converter, $uri, $text, $liveCompletionOffset)) ?? [];
        self::assertSame(['utf8'], array_column($completion, 'label'));
        /** @var array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}} $textEdit */
        $textEdit = $completion[0]['textEdit'];
        self::assertSame($this->protocolRange($fixture->converter, $text, $liveCompletionStart, \strlen('ut')), $textEdit['range']);
    }

    public function testIgnoresCommentedYamlResourceLinks(): void
    {
        $fixture = $this->providers();
        $uri = 'file:///workspace/config/packages/framework.yaml';
        $text = "# resource: ignored.yaml\nimports:\n    - { resource: ../shared.yaml }\n";
        $fixture->documents->open(new Document($uri, 'yaml', 1, $text));

        $links = $fixture->links->links(['textDocument' => ['uri' => $uri]]) ?? [];
        $resourceOffset = (int) strpos($text, '../shared.yaml');
        self::assertSame(['file:///workspace/config/shared.yaml'], array_column($links, 'target'));
        self::assertSame($this->protocolRange($fixture->converter, $text, $resourceOffset, \strlen('../shared.yaml')), $links[0]['range'] ?? null);
    }

    public function testUsesVendorAuthorityForSavedConfiguration(): void
    {
        $root = sys_get_temp_dir().'/symfony-lsp-'.bin2hex(random_bytes(8));
        mkdir($root.'/config/packages', 0777, true);
        $text = "framework:\n    router:\n        utf8: maybe\n    mystery: true\n";
        file_put_contents($root.'/config/packages/framework.yaml', $text);
        $uri = (new UriToPathConverter())->toUri($root.'/config/packages/framework.yaml');

        try {
            $valid = $this->providers($root, validation: new ConfigurationValidationResult(ConfigurationValidationResult::VALID, 'dev'));
            $valid->documents->open(new Document($uri, 'yaml', 1, $text));
            $savedWarnings = $valid->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]);
            self::assertIsArray($savedWarnings);
            self::assertSame(['config.invalid_type', 'config.unknown_key'], array_column($savedWarnings, 'code'));
            self::assertSame([2, 2], array_column($savedWarnings, 'severity'));
            $valid->documents->update($uri, 2, $text."    another_mystery: true\n");
            $provisional = $valid->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]);
            self::assertIsArray($provisional);
            self::assertSame([2, 2, 2], array_column($provisional, 'severity'));

            $invalidText = $text."framework:\n    router:\n        utf8: true\n";
            file_put_contents($root.'/config/packages/framework.yaml', $invalidText);
            $invalid = $this->providers($root, validation: new ConfigurationValidationResult(
                ConfigurationValidationResult::INVALID,
                'dev',
                'yaml',
                file: 'config/packages/framework.yaml',
                line: 3,
            ));
            $invalid->documents->open(new Document($uri, 'yaml', 1, $invalidText));
            $diagnostics = $invalid->diagnostics->diagnostics(['textDocument' => ['uri' => $uri]]);
            self::assertIsArray($diagnostics);
            self::assertSame('config.malformed_structure', $diagnostics[0]['code'] ?? null);
            self::assertSame(1, $diagnostics[0]['severity'] ?? null);
            self::assertContains('config.duplicate_key', array_column($diagnostics, 'code'));
            self::assertSame(array_fill(0, \count($diagnostics) - 1, 2), array_column(\array_slice($diagnostics, 1), 'severity'));
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    /** @return array{textDocument: array{uri: string}, position: array{line: int, character: int}} */
    private function positionParams(PositionConverter $converter, string $uri, string $text, int $offset): array
    {
        $position = $converter->toPosition($text, $offset);

        return ['textDocument' => ['uri' => $uri], 'position' => ['line' => $position->line, 'character' => $position->character]];
    }

    /** @return array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
    private function protocolRange(PositionConverter $converter, string $text, int $offset, int $length): array
    {
        $start = $converter->toPosition($text, $offset);
        $end = $converter->toPosition($text, $offset + $length);

        return [
            'start' => ['line' => $start->line, 'character' => $start->character],
            'end' => ['line' => $end->line, 'character' => $end->character],
        ];
    }

    private function providers(string $root = '/workspace', string $environment = 'dev', ?ConfigurationValidationResult $validation = null): ConfigurationProviderFixture
    {
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $uriConverter = new UriToPathConverter();
        $project = new Project($root, $uriConverter->toUri($root), '^8.0');
        $projects->replace([$project]);
        $converter = new PositionConverter();
        $indexes = new ConfigurationIndexRegistry();
        $routeIndexes = new RouteIndexRegistry();
        $routeIndexes->forProject($project)->replaceRuntime(['config/http_endpoints.yaml', 'config/routes/framework.yaml'], []);
        $environmentIndexes = new EnvironmentIndexRegistry();
        $environmentIndexes->forProject($project)->replaceProcessors(['bool' => 'bool', 'json' => 'array']);
        $validations = new ConfigurationValidationRegistry();
        if (null !== $validation) {
            $validations->replace($project, $validation);
        }
        $runtimeConfiguration = new RuntimeConfiguration();
        $runtimeConfiguration->configure(['environment' => $environment]);
        (new ProjectConfigurationSnapshotLoader($indexes))->load($project, ['bundles' => [
            [
                'alias' => 'framework',
                'tree' => $this->node('framework', 'array', children: [
                    $this->node('router', 'array', children: [
                        $this->node('utf8', 'boolean', info: 'Use UTF-8 routes.'),
                        $this->node('strict', 'boolean'),
                        $this->node('mode', 'enum', deprecated: true, allowedValues: ['dev', 'prod']),
                        $this->node('reset_mode', 'enum', allowedValues: ['schema', 'migrate'], allowedEnumCases: ['App\\ResetMode::SCHEMA', 'App\\ResetMode::MIGRATE']),
                        $this->node('strict_reset_mode', 'enum', allowedEnumCases: ['App\\ResetMode::SCHEMA', 'App\\ResetMode::MIGRATE']),
                    ]),
                    $this->node('normalized_section', 'array', children: [
                        $this->node('nested_key', 'boolean'),
                        $this->node('mixed_nested_key', 'boolean'),
                        $this->node('twin_key', 'boolean'),
                    ]),
                    $this->node('exact_keys', 'array', children: [
                        $this->node('default-src', 'boolean'),
                    ], normalizeKeys: false),
                    $this->node('exact_items', 'array', prototype: $this->node('exact_item', 'array', children: [
                        $this->node('default-src', 'boolean'),
                    ], normalizeKeys: false)),
                    $this->node('required_parent', 'array', children: [
                        $this->node('known', 'boolean'),
                        $this->node('token', 'scalar', required: true),
                    ]),
                    $this->node('cache', 'array', children: [
                        $this->node('pools', 'array', prototype: $this->node('pool', 'array', children: [
                            $this->node('adapters', 'array', accepts: ['scalar' => true]),
                        ], aliases: ['adapter' => 'adapters']), keyAttribute: 'name'),
                    ]),
                    $this->node('session', 'array', children: [
                        $this->node('cookie_secure', 'enum', allowedValues: [true, false, 'auto']),
                    ]),
                    $this->node('items', 'array', prototype: $this->node('item', 'array', children: [
                        $this->node('name', 'boolean'),
                        $this->node('handlers', 'array', prototype: $this->node('handler', 'array', children: [
                            $this->node('type', 'scalar'),
                            $this->node('nested', 'boolean'),
                        ]), keyAttribute: 'name'),
                        $this->node('policies', 'array', prototype: $this->node('policy', 'array', children: [
                            $this->node('default-src', 'boolean'),
                        ], normalizeKeys: false)),
                    ]), keyAttribute: 'name'),
                    $this->node('assets', 'array', accepts: ['null' => true, 'true' => true, 'false' => true, 'scalar' => false, 'unknownKeys' => false], children: [
                        $this->node('enabled', 'boolean'),
                    ]),
                    $this->node('loose', 'array', accepts: ['unknownKeys' => true], children: [
                        $this->node('known', 'boolean'),
                        $this->node('strict', 'array', children: [
                            $this->node('known', 'boolean'),
                        ]),
                    ]),
                    $this->node('dispatch', 'array', prototype: $this->node('sender', 'array', accepts: ['scalar' => true], children: [
                        $this->node('senders', 'array'),
                    ])),
                ]),
            ],
            [
                'alias' => 'monolog',
                'tree' => $this->node('monolog', 'array', children: [
                    $this->node('handlers', 'array', prototype: $this->node('handler', 'array', children: [
                        $this->node('name', 'scalar'),
                        $this->node('type', 'scalar'),
                        $this->node('path', 'scalar'),
                        $this->node('level', 'enum', allowedValues: ['debug', 'info']),
                        $this->node('nested', 'boolean'),
                    ]), keyAttribute: 'name'),
                ]),
            ],
            [
                'alias' => 'security',
                'tree' => $this->node('security', 'array', children: [
                    $this->node('password_hashers', 'array', prototype: $this->node('password_hasher', 'array', children: [
                        $this->node('algorithm', 'scalar'),
                        $this->node('migrate_from', 'array'),
                    ]), keyAttribute: 'class'),
                    $this->node('firewalls', 'array', prototype: $this->node('firewall', 'array', children: [
                        $this->node('pattern', 'scalar'),
                        $this->node('remember_me', 'array', children: [
                            $this->node('secret', 'scalar'),
                            $this->node('name', 'scalar'),
                            $this->node('always_remember_me', 'boolean'),
                        ]),
                    ]), keyAttribute: 'name'),
                ]),
            ],
            [
                'alias' => 'services',
                'tree' => $this->node('services', 'array'),
            ],
        ]]);
        $resolver = new DocumentContextResolver($documents, $projects);
        $protocol = new LspProtocolMapper();
        $phpComments = new PhpCommentParser();
        $xmlComments = new XmlCommentParser();
        $treeSitter = new NativeTreeSitterParser(new TreeSitterResultDecoder());
        $yamlComments = new YamlCommentParser($treeSitter);
        $php = new PhpConfigurationAnalyzer(new TolerantPhpParser(new Parser()), $phpComments);
        $xml = new XmlConfigurationAnalyzer($xmlComments);
        $yaml = new YamlConfigurationParser($converter, new YamlDocumentParser($treeSitter));
        $values = new ConfigurationValueValidator($environmentIndexes, new EnvironmentExpressionParser());
        $validationReconciler = new ConfigurationValidationReconciler(
            $validations,
            new SavedDocumentMatcher(new ProjectPathResolver($uriConverter)),
            $runtimeConfiguration,
            $converter,
            $protocol,
        );

        return new ConfigurationProviderFixture(
            new ConfigurationCompletionProvider($resolver, $converter, $protocol, $indexes, $yaml, $php, $xml),
            new ConfigurationHoverProvider($resolver, $converter, $protocol, $indexes, $yaml, $php, $xml),
            new ConfigurationDiagnosticProvider($resolver, new ProjectPathResolver($uriConverter), $converter, $protocol, $indexes, $routeIndexes, $runtimeConfiguration, $yaml, $values, $php, $xml, $validationReconciler),
            new ConfigurationDocumentLinkProvider($resolver, $converter, $protocol, $uriConverter, $yamlComments),
            $documents,
            $converter,
        );
    }

    /**
     * @param array<array-key, array<array-key, mixed>> $children
     * @param list<string|int|float|bool|null>          $allowedValues
     * @param list<string>                              $allowedEnumCases
     * @param array<array-key, mixed>|null              $prototype
     * @param array<string, bool>                       $accepts
     * @param array<string, string>                     $aliases
     *
     * @return array<array-key, mixed>
     */
    private function node(string $name, string $type, array $children = [], ?string $info = null, bool $deprecated = false, array $allowedValues = [], array $allowedEnumCases = [], bool $required = false, ?array $prototype = null, array $accepts = [], array $aliases = [], ?string $keyAttribute = null, bool $normalizeKeys = true): array
    {
        return ['name' => $name, 'type' => $type, 'required' => $required, 'hasDefault' => false, 'defaultSummary' => null, 'info' => $info, 'example' => null, 'deprecated' => $deprecated, 'allowedValues' => $allowedValues, 'allowedEnumCases' => $allowedEnumCases, 'children' => $children, 'prototype' => $prototype, 'accepts' => $accepts, 'aliases' => $aliases, 'keyAttribute' => $keyAttribute, 'normalizeKeys' => $normalizeKeys];
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
