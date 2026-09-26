<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDiagnosticProvider;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\Parameter;
use Symfony\Lsp\Feature\DependencyInjection\ParameterExpressionScanner;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionReferenceExtractor;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Tests\Support\EnvironmentScopes;

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
        $provider = $this->provider($uri, $text);

        $diagnostics = $provider->diagnostics(['textDocument' => ['uri' => $uri]]);

        self::assertSame(
            ['service.not_found', 'service.not_found', 'parameter.not_found'],
            array_column($diagnostics ?? [], 'code'),
        );
        self::assertSame([
            'Service "missing.service" does not exist in the selected environment.',
            'Service "test.only" does not exist in the selected environment.',
            'Parameter "missing.parameter" does not exist in the selected environment.',
        ], array_column($diagnostics ?? [], 'message'));
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
        $provider = $this->provider($uri, $text, parameters: ['root_dir', 'document_folder']);

        self::assertSame([], $provider->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    public function testReportsNoDiagnosticsWhileBothRuntimeIndexesAreIncomplete(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            services:
                app.consumer:
                    arguments: ['@missing.service', '%missing.parameter%']
            YAML;
        $provider = $this->provider($uri, $text, indexesComplete: false);

        self::assertSame([], $provider->diagnostics(['textDocument' => ['uri' => $uri]]));
    }

    /** @param list<string> $parameters */
    private function provider(string $uri, string $text, array $parameters = [], bool $indexesComplete = true): DependencyInjectionDiagnosticProvider
    {
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace($indexesComplete);
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(
            $indexesComplete,
            ...array_map(static fn (string $name): Parameter => new Parameter($name, null), $parameters),
        );
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor(
            new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
            new YamlDependencyInjectionDeclarationExtractor($converter),
            new YamlDependencyInjectionReferenceExtractor($converter, new ParameterExpressionScanner()),
        );
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($yamlExtractor->extract($uri, $text));

        return new DependencyInjectionDiagnosticProvider(
            new DocumentContextResolver($documents, $projects),
            new LspProtocolMapper(),
            $serviceIndexes,
            $parameterIndexes,
            $sourceIndexes,
            EnvironmentScopes::resolver(),
        );
    }
}
