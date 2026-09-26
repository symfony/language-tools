<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDiagnosticProvider;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class DependencyInjectionDiagnosticProviderTest extends TestCase
{
    public function testDiagnosesOnlyDefinitelyUnknownServicesAndParameters(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            parameters:
                app.known: value
            services:
                app.known: ~
                app.consumer:
                    arguments: ['@missing.service', '@test.only', '@?optional.service', '@app.known', '%missing.parameter%', '%app.known%']
                    tags: ['unknown.tag']
            when@test:
                services:
                    test.only: ~
                    app.test_consumer:
                        arguments: ['%test.client.parameters%']
            YAML;
        $kit = $this->kit($uri, $text);

        $diagnostics = $kit->get(DependencyInjectionDiagnosticProvider::class)->diagnostics($kit->document($uri));

        self::assertSame(
            ['service.not_found', 'service.not_found', 'parameter.not_found'],
            $kit->codes($diagnostics),
        );
        self::assertSame([
            'Service "missing.service" does not exist in the selected environment.',
            'Service "test.only" does not exist in the selected environment.',
            'Parameter "missing.parameter" does not exist in the selected environment.',
        ], $kit->messages($diagnostics));
    }

    public function testAcceptsAdjacentParameterReferences(): void
    {
        $uri = 'file:///workspace/config/packages/liip_imagine.yaml';
        $text = <<<'YAML'
            liip.storage:
                visibility: 'public'
                directory_visibility: 'public'
                local:
                    directory: "%root_dir%%document_folder%"
            YAML;
        $kit = $this->kit($uri, $text, parameters: ['root_dir', 'document_folder']);

        self::assertSame([], $kit->get(DependencyInjectionDiagnosticProvider::class)->diagnostics($kit->document($uri)));
    }

    public function testReportsNoDiagnosticsWhileBothRuntimeIndexesAreIncomplete(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            services:
                app.consumer:
                    arguments: ['@missing.service', '%missing.parameter%']
            YAML;
        $kit = $this->kit($uri, $text, indexesComplete: false);

        self::assertSame([], $kit->get(DependencyInjectionDiagnosticProvider::class)->diagnostics($kit->document($uri)));
    }

    /** @param list<string> $parameters */
    private function kit(string $uri, string $text, array $parameters = [], bool $indexesComplete = true): ProjectTestKit
    {
        return (new ProjectTestKit())->open($uri, $text)->index()->runtime('container', [
            'servicesComplete' => $indexesComplete,
            'items' => [],
            'parametersComplete' => $indexesComplete,
            'parameters' => array_map(static fn (string $name): array => ['name' => $name], $parameters),
        ]);
    }
}
