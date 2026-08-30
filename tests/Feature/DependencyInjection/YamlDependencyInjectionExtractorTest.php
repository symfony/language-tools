<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;

final class YamlDependencyInjectionExtractorTest extends TestCase
{
    public function testExtractsParameterReferencesFromConfigurationSections(): void
    {
        $extractor = new YamlDependencyInjectionExtractor(new PositionConverter());
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
        $facts = (new YamlDependencyInjectionExtractor(new PositionConverter()))->extract(
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
}
