<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDiagnosticProvider;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDocumentExtractor;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\PhpClassDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\XmlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionDeclarationExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionReferenceExtractor;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Protocol\LspProtocolMapper;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

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

    /** @param list<string> $expectedCodes */
    #[DataProvider('environmentScopedFileProvider')]
    public function testScopesConventionalEnvironmentFiles(string $uri, string $environment, array $expectedCodes): void
    {
        $text = <<<'YAML'
            services:
                app.consumer:
                    arguments: ['@missing.service', '%missing.parameter%']
            YAML;
        $provider = $this->provider($uri, $text, $environment);

        self::assertSame(
            $expectedCodes,
            array_column($provider->diagnostics(['textDocument' => ['uri' => $uri]]) ?? [], 'code'),
        );
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function environmentScopedFileProvider(): iterable
    {
        $diagnostics = ['service.not_found', 'parameter.not_found'];

        yield 'inactive service file' => ['file:///workspace/config/services_test.yaml', 'dev', []];
        yield 'active service file' => ['file:///workspace/config/services_test.yaml', 'test', $diagnostics];
        yield 'active service file with yml extension' => ['file:///workspace/config/services_dev.yml', 'dev', $diagnostics];
        yield 'inactive package directory' => ['file:///workspace/config/packages/test/framework.yaml', 'dev', []];
        yield 'active package directory' => ['file:///workspace/config/packages/test/framework.yaml', 'test', $diagnostics];
        yield 'inactive route directory' => ['file:///workspace/config/routes/test/api.yaml', 'dev', []];
        yield 'package file lookalike' => ['file:///workspace/config/packages/test.yaml', 'dev', $diagnostics];
        yield 'service file outside config' => ['file:///workspace/src/services_test.yaml', 'dev', $diagnostics];
    }

    private function provider(string $uri, string $text, string $environment = 'dev'): DependencyInjectionDiagnosticProvider
    {
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace')]);
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(true);
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(true);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor(
            new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
            new YamlDependencyInjectionDeclarationExtractor($converter),
            new YamlDependencyInjectionReferenceExtractor($converter),
        );
        $phpParser = new TolerantPhpParser(new Parser());
        $runtimeConfiguration = new RuntimeConfiguration();
        $runtimeConfiguration->configure(['environment' => $environment]);

        return new DependencyInjectionDiagnosticProvider(
            new DocumentContextResolver($documents, $projects),
            new LspProtocolMapper(),
            $serviceIndexes,
            $parameterIndexes,
            new DependencyInjectionDocumentExtractor(
                $yamlExtractor,
                new XmlDependencyInjectionExtractor($converter),
                new PhpAutowireReferenceExtractor($converter, $phpParser),
                new PhpClassDeclarationExtractor($converter, $phpParser),
            ),
            new ProjectPathResolver(new UriToPathConverter()),
            $runtimeConfiguration,
        );
    }
}
