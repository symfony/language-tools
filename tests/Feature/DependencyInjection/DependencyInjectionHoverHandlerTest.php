<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionHoverHandler;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceFacts;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\ServiceDeclaration;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class DependencyInjectionHoverHandlerTest extends TestCase
{
    public function testDisplaysSafeServiceAndParameterMetadata(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            parameters:
                app.api_key: 'CANARY_SECRET_VALUE'
            services:
                app.consumer:
                    arguments: ['@app.mailer', '%app.api_key%']
            YAML;
        $kit = (new ProjectTestKit())->open($uri, $text)->index()->runtime('container', [
            'servicesComplete' => true,
            'items' => [[
                'id' => 'app.mailer',
                'class' => 'App\\Mailer',
                'public' => false,
                'lazy' => true,
                'deprecation' => 'Use app.new_mailer.',
                'tags' => ['kernel.reset'],
                'decorates' => 'mailer',
                'autowiringTypes' => ['App\\MailerInterface'],
                'decorationStack' => ['app.mailer', 'mailer.inner'],
            ]],
            'parametersComplete' => true,
            'parameters' => [['name' => 'app.api_key', 'deprecation' => 'Use app.new_api_key.']],
        ]);
        $handler = $kit->get(DependencyInjectionHoverHandler::class);

        $serviceHover = $handler->hover($kit->positioned($kit->inside($uri, 'app.mailer')));
        $parameterHover = $handler->hover($kit->positioned($kit->inside($uri, 'app.api_key%')));

        self::assertSame(<<<'MARKDOWN'
            Service: `app.mailer`

            Class: `App\Mailer`

            Visibility: private

            Lazy: yes

            Deprecated: Use app.new_mailer.

            Decorates: `mailer`

            Tags: `kernel.reset`

            Autowiring types: `App\MailerInterface`

            Decoration stack: `app.mailer` → `mailer.inner`
            MARKDOWN, $kit->hoverText($serviceHover));
        self::assertSame(<<<'MARKDOWN'
            Parameter: `app.api_key`

            Deprecated: Use app.new_api_key.
            MARKDOWN, $kit->hoverText($parameterHover));
        self::assertStringNotContainsString(
            'CANARY_SECRET_VALUE',
            json_encode([$serviceHover, $parameterHover], \JSON_THROW_ON_ERROR),
        );
    }

    public function testUsesRuntimeServiceMetadataWithSourceFallbacks(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = "services:\n    app.shared: ~\n";
        $kit = (new ProjectTestKit())->open($uri, $text)->runtime('container', [
            'servicesComplete' => true,
            'items' => [[
                'id' => 'app.shared',
                'class' => 'App\\RuntimeShared',
                'public' => false,
                'lazy' => true,
                'deprecation' => 'Use app.replacement.',
                'decorates' => 'runtime.decorated',
                'autowiringTypes' => ['App\\SharedInterface'],
                'decorationStack' => ['app.shared', 'app.inner'],
            ]],
        ]);
        $parsedDeclaration = $kit->get(YamlDependencyInjectionExtractor::class)->extract($uri, $text)->services[0];
        $kit->get(DependencyInjectionSourceIndexRegistry::class)->forProject($kit->project())->replace(new DependencyInjectionSourceFacts($uri, [new ServiceDeclaration(
            'app.shared',
            $uri,
            $parsedDeclaration->range,
            'App\\SourceShared',
            'source.alias',
            'source.decorated',
            ['source.tag'],
        )]));

        $hover = $kit->get(DependencyInjectionHoverHandler::class)->hover($kit->positioned($kit->inside($uri, 'app.shared')));

        self::assertSame(<<<'MARKDOWN'
            Service: `app.shared`

            Alias of: `source.alias`

            Class: `App\RuntimeShared`

            Visibility: private

            Lazy: yes

            Deprecated: Use app.replacement.

            Decorates: `runtime.decorated`

            Autowiring types: `App\SharedInterface`

            Decoration stack: `app.shared` → `app.inner`
            MARKDOWN, $kit->hoverText($hover));
    }
}
