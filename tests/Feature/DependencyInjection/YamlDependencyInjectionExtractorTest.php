<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionReferenceExtractor;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;

/** @phpstan-type FactsData array{services: list<array{string, string, string|null, string|null, string|null, list<string>}>, parameters: list<array{string, string}>, references: list<array{string, string, string, bool}>} */
final class YamlDependencyInjectionExtractorTest extends TestCase
{
    public function testExtractsParameterReferencesFromConfigurationSections(): void
    {
        $extractor = $this->extractor();
        $yaml = <<<'YAML'
            framework:
                assets:
                    json_manifest_path: '%kernel.project_dir%/public/build/manifest.json' # keep %commented.parameter% in documentation
            YAML;

        $configuration = $extractor->extract('file:///workspace/config/packages/assets.yaml', $yaml);
        self::assertSame(['kernel.project_dir'], array_map(static fn ($reference): string => $reference->name, $configuration->references));

        // outside a config directory the same yaml could be a translation catalog with literal placeholders
        $catalog = $extractor->extract('file:///workspace/translations/messages.en.yaml', "greeting: 'Hello %name%'\n");
        self::assertSame([], $catalog->references);

        // %% escapes a literal percent, as in monolog line formats
        $monolog = $extractor->extract('file:///workspace/config/services.yaml', <<<'YAML'
            monolog:
                handlers:
                    main:
                        formatter: "[%%datetime%%] %%message%% in %kernel.environment%"
            YAML);
        self::assertSame(['kernel.environment'], array_map(static fn ($reference): string => $reference->name, $monolog->references));
    }

    public function testExtractsDeclarationsMetadataAndStaticReferencesWithoutValues(): void
    {
        $facts = $this->extractor()->extract(
            'file:///workspace/config/services.yaml',
            <<<'YAML'
                framework:
                    services:
                        fake.nested: true

                parameters:
                    app.api_key: 'CANARY_SECRET_VALUE'
                    app.storage_dir: '%kernel.project_dir%/storage' # @commented.service %commented.parameter%
                    app.message: |
                        Prefix # %kernel.project_dir%/suffix

                services:
                    _defaults:
                        bind:
                            string $storageDir: '%app.storage_dir%'

                    app.mailer:
                        class: App\Mailer
                        arguments: ['@logger', '@?optional.mailer', '@@escaped', '@=service("dynamic")', 'support@app.symfony.com']
                        tags: ['mailer.transport', { name: kernel.reset }]

                    mailer: '@app.mailer'

                    app.decorator:
                        decorates: app.mailer
                YAML,
        );

        self::assertSame(
            ['app.api_key', 'app.storage_dir', 'app.message'],
            array_map(static fn ($declaration): string => $declaration->name, $facts->parameters),
        );
        self::assertSame(
            ['app.mailer', 'mailer', 'app.decorator'],
            array_map(static fn ($declaration): string => $declaration->id, $facts->services),
        );
        self::assertSame('App\\Mailer', $facts->services[0]->className);
        self::assertSame(['mailer.transport', 'kernel.reset'], $facts->services[0]->tags);
        self::assertSame('app.mailer', $facts->services[1]->alias);
        self::assertSame('app.mailer', $facts->services[2]->decorates);
        self::assertSame([
            ['parameter', 'kernel.project_dir', false],
            ['parameter', 'kernel.project_dir', false],
            ['parameter', 'app.storage_dir', false],
            ['service', 'logger', false],
            ['service', 'optional.mailer', true],
            ['service', 'app.mailer', false],
            ['service', 'app.mailer', false],
        ], array_map(
            static fn ($reference): array => [
                $reference->kind->value,
                $reference->name,
                $reference->optional,
            ],
            $facts->references,
        ));
        self::assertStringNotContainsString(
            'CANARY_SECRET_VALUE',
            implode(' ', [
                ...array_map(static fn ($declaration): string => $declaration->name, $facts->parameters),
                ...array_map(static fn ($declaration): string => $declaration->id, $facts->services),
            ]),
        );
    }

    /** @param FactsData $expected */
    #[DataProvider('runtimeConfigurationProvider')]
    public function testPreservesRuntimeConfigurationFixtureExtraction(string $path, array $expected): void
    {
        $text = file_get_contents(__DIR__.'/../../Fixtures/RuntimeApplication/config/'.$path);
        self::assertIsString($text);

        self::assertSame(
            $expected,
            self::factsData($this->extractor()->extract('file:///workspace/config/'.$path, $text)),
        );
    }

    /** @return iterable<string, array{string, FactsData}> */
    public static function runtimeConfigurationProvider(): iterable
    {
        yield 'services' => [
            'services.yaml',
            [
                'services' => [
                    ['App\\', '8:4-8:8', 'App\\', null, null, []],
                    ['app.fixture_controller', '14:4-14:26', 'App\\Controller\\HomeController', null, null, []],
                    ['App\\Environment\\CustomEnvVarProcessor', '17:4-17:41', 'App\\Environment\\CustomEnvVarProcessor', null, null, ['container.env_var_processor']],
                ],
                'parameters' => [
                    ['app.fixture_name', '1:4-1:20'],
                ],
                'references' => [],
            ],
        ];
        yield 'framework package' => [
            'packages/framework.yaml',
            [
                'services' => [],
                'parameters' => [],
                'references' => [
                    ['parameter', 'kernel.project_dir', '5:16-5:34', false],
                    ['parameter', 'kernel.project_dir', '7:24-7:42', false],
                ],
            ],
        ];
        yield 'twig package' => [
            'packages/twig.yaml',
            [
                'services' => [],
                'parameters' => [],
                'references' => [
                    ['parameter', 'kernel.project_dir', '1:20-1:38', false],
                ],
            ],
        ];
        foreach (['http_endpoints.yaml', 'routes.yaml', 'packages/security.yaml', 'packages/twig_component.yaml'] as $path) {
            yield $path => [$path, ['services' => [], 'parameters' => [], 'references' => []]];
        }
    }

    /** @param FactsData $expected */
    #[DataProvider('yamlSyntaxProvider')]
    public function testPreservesYamlSyntaxExtraction(string $yaml, ?string $environment, array $expected): void
    {
        self::assertSame(
            $expected,
            self::factsData($this->extractor()->extract('file:///workspace/config/services.yaml', $yaml, $environment)),
        );
    }

    /** @return iterable<string, array{string, string|null, FactsData}> */
    public static function yamlSyntaxProvider(): iterable
    {
        yield 'quoted keys, tags, block values, sequences, and comments' => [
            <<<'YAML'
                "parameters":
                    'quoted.parameter': '%kernel.project_dir%/quoted'

                "services":
                    'app.quoted':
                        class: 'App\Actual'
                        arguments:
                            - !service '@app.block_item'
                            # between values
                            - '@?app.optional'
                        tags:
                            # between tags
                            - 'kernel.event_subscriber'
                            - { name: 'console.command', priority: 10 }

                    app.block:
                        arguments: |-
                            @app.block_service
                            %app.block_parameter%

                    app.alias: !service '@app.quoted'
                YAML,
            null,
            [
                'services' => [
                    ['app.quoted', '4:5-4:15', 'App\\Actual', null, null, ['kernel.event_subscriber', 'console.command']],
                    ['app.block', '15:4-15:13', null, null, null, []],
                    ['app.alias', '20:4-20:13', null, null, null, []],
                ],
                'parameters' => [
                    ['quoted.parameter', '1:5-1:21'],
                ],
                'references' => [
                    ['parameter', 'kernel.project_dir', '1:26-1:44', false],
                    ['service', 'app.block_item', '7:25-7:39', false],
                    ['service', 'app.optional', '9:17-9:29', true],
                    ['service', 'app.block_service', '17:13-17:30', false],
                    ['parameter', 'app.block_parameter', '18:13-18:32', false],
                    ['service', 'app.quoted', '20:26-20:36', false],
                ],
            ],
        ];
        yield 'matching environment' => [
            <<<'YAML'
                services:
                    app.base:
                        arguments: ['@app.base_dependency']
                when@test:
                    parameters:
                        app.test_parameter: '%kernel.environment%'
                    services:
                        app.test:
                            decorates: app.base
                            arguments: ['@app.test_dependency']
                when@prod:
                    services:
                        app.prod:
                            alias: app.base
                            arguments: ['@app.prod_dependency']
                YAML,
            'test',
            [
                'services' => [
                    ['app.base', '1:4-1:12', null, null, null, []],
                    ['app.test', '7:8-7:16', null, null, 'app.base', []],
                ],
                'parameters' => [
                    ['app.test_parameter', '5:8-5:26'],
                ],
                'references' => [
                    ['service', 'app.base_dependency', '2:22-2:41', false],
                    ['parameter', 'kernel.environment', '5:30-5:48', false],
                    ['service', 'app.base', '8:23-8:31', false],
                    ['service', 'app.test_dependency', '9:26-9:45', false],
                ],
            ],
        ];
        yield 'malformed document' => [
            <<<'YAML'
                parameters:
                    before: '%before%'
                services:
                    app.broken:
                        arguments: ['@first'
                        tags:
                            - kernel.reset
                    app.after:
                        decorates: app.broken
                        arguments:
                            - '@after'
                YAML,
            null,
            [
                'services' => [
                    ['app.broken', '3:4-3:14', null, null, null, ['kernel.reset']],
                    ['app.after', '7:4-7:13', null, null, 'app.broken', []],
                ],
                'parameters' => [
                    ['before', '1:4-1:10'],
                ],
                'references' => [
                    ['parameter', 'before', '1:14-1:20', false],
                    ['service', 'first', '4:22-4:27', false],
                    ['service', 'app.broken', '8:19-8:29', false],
                    ['service', 'after', '10:16-10:21', false],
                ],
            ],
        ];
    }

    private function extractor(): YamlDependencyInjectionExtractor
    {
        $converter = new PositionConverter();

        return new YamlDependencyInjectionExtractor(
            new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
            new YamlDependencyInjectionDeclarationExtractor($converter),
            new YamlDependencyInjectionReferenceExtractor($converter),
        );
    }

    /** @return FactsData */
    private static function factsData(DependencyInjectionSourceFacts $facts): array
    {
        return [
            'services' => array_map(static fn ($service): array => [
                $service->id,
                self::rangeData($service->range),
                $service->className,
                $service->alias,
                $service->decorates,
                $service->tags,
            ], $facts->services),
            'parameters' => array_map(static fn ($parameter): array => [
                $parameter->name,
                self::rangeData($parameter->range),
            ], $facts->parameters),
            'references' => array_map(static fn ($reference): array => [
                $reference->kind->value,
                $reference->name,
                self::rangeData($reference->range),
                $reference->optional,
            ], $facts->references),
        ];
    }

    private static function rangeData(Range $range): string
    {
        return $range->start->line.':'.$range->start->character.'-'.$range->end->line.':'.$range->end->character;
    }
}
