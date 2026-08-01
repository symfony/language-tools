<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionDiagnosticProvider;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\PhpAutowireReferenceExtractor;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\YamlDependencyInjectionExtractor;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

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
                    arguments: ['@missing.service', '@?optional.service', '@app.known', '%missing.parameter%', '%app.known%']
                    tags: ['unknown.tag']
            YAML;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(true);
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(true);
        $converter = new PositionConverter();
        $yamlExtractor = new YamlDependencyInjectionExtractor($converter);
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        $sourceIndexes->forProject($project)->replace($yamlExtractor->extract($uri, $text));
        $provider = new DependencyInjectionDiagnosticProvider(
            $documents,
            $projects,
            $serviceIndexes,
            $parameterIndexes,
            $sourceIndexes,
            $yamlExtractor,
            new PhpAutowireReferenceExtractor($converter, new TolerantPhpParser(new Parser())),
        );

        $diagnostics = $provider->diagnostics(['textDocument' => ['uri' => $uri]]);

        self::assertSame(
            ['service.not_found', 'parameter.not_found'],
            array_column($diagnostics ?? [], 'code'),
        );
        self::assertSame([
            'Service "missing.service" does not exist in the selected environment.',
            'Parameter "missing.parameter" does not exist in the selected environment.',
        ], array_column($diagnostics ?? [], 'message'));
    }
}
