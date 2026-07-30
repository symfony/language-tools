<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\Parameter;
use Symfony\Lsp\Feature\DependencyInjection\ParameterIndexRegistry;
use Symfony\Lsp\Feature\DependencyInjection\ProjectServiceSnapshotLoader;
use Symfony\Lsp\Feature\DependencyInjection\Service;
use Symfony\Lsp\Feature\DependencyInjection\ServiceCompletionHandler;
use Symfony\Lsp\Feature\DependencyInjection\ServiceIndexRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class ServiceCompletionHandlerTest extends TestCase
{
    public function testCompletesRuntimeServiceIdsWithoutExposingParameters(): void
    {
        $uri = 'file:///workspace/config/services.yaml';
        $text = <<<'YAML'
            services:
                App\Controller\DemoController:
                    arguments: ['@app.ma']
            YAML;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'yaml', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new ServiceIndexRegistry();
        $parameterIndexes = new ParameterIndexRegistry();
        $sourceIndexes = new DependencyInjectionSourceIndexRegistry();
        (new ProjectServiceSnapshotLoader($indexes, $parameterIndexes))->load($project, [
            'sections' => [
                'container' => [
                    'complete' => true,
                    'items' => [
                        [
                            'id' => 'app.mailer',
                            'class' => 'App\\Mailer',
                            'tags' => ['kernel.reset'],
                        ],
                        [
                            'id' => 'app.storage',
                            'class' => 'App\\Storage',
                        ],
                    ],
                    'parameters' => [
                        ['name' => 'app.api_key', 'value' => 'CANARY_SECRET_VALUE'],
                    ],
                ],
            ],
        ]);
        $converter = new PositionConverter();
        $cursor = strpos($text, 'app.ma') + \strlen('app.ma');
        $position = $converter->toPosition($text, $cursor);
        $handler = new ServiceCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            $indexes,
            $parameterIndexes,
            $sourceIndexes,
        );

        $result = $handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ]);

        self::assertSame([[
            'label' => 'app.mailer',
            'kind' => 18,
            'detail' => 'App\\Mailer',
            'textEdit' => [
                'range' => [
                    'start' => ['line' => 2, 'character' => 22],
                    'end' => ['line' => 2, 'character' => 28],
                ],
                'newText' => 'app.mailer',
            ],
        ]], $result);
        self::assertStringNotContainsString(
            'CANARY_SECRET_VALUE',
            json_encode($result, \JSON_THROW_ON_ERROR),
        );
    }

    public function testCompletesParametersInYamlAndPhpAttributes(): void
    {
        $documents = new DocumentStore();
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $serviceIndexes = new ServiceIndexRegistry();
        $serviceIndexes->forProject($project)->replace(true, new Service(
            'app.mailer',
            'App\\Mailer',
            null,
            false,
            false,
            null,
            [],
            null,
            [],
        ));
        $parameterIndexes = new ParameterIndexRegistry();
        $parameterIndexes->forProject($project)->replace(
            true,
            new Parameter('app.storage_dir', null),
            new Parameter('app.api_key', 'Use app.new_api_key.'),
        );
        $converter = new PositionConverter();
        $handler = new ServiceCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            $serviceIndexes,
            $parameterIndexes,
            new DependencyInjectionSourceIndexRegistry(),
        );

        $yamlUri = 'file:///workspace/config/services.yaml';
        $yaml = "arguments: ['%app.st']";
        $documents->open(new Document($yamlUri, 'yaml', 1, $yaml));
        $yamlPosition = $converter->toPosition($yaml, strpos($yaml, 'app.st') + \strlen('app.st'));
        $yamlResult = $handler->complete([
            'textDocument' => ['uri' => $yamlUri],
            'position' => ['line' => $yamlPosition->line(), 'character' => $yamlPosition->character()],
        ]);
        self::assertIsArray($yamlResult);
        self::assertIsArray($yamlResult[0]['textEdit']);

        self::assertSame('app.storage_dir', $yamlResult[0]['label'] ?? null);
        self::assertSame('app.storage_dir%', $yamlResult[0]['textEdit']['newText'] ?? null);

        $phpUri = 'file:///workspace/src/Service.php';
        $php = "<?php #[Autowire(param: 'app.a')] final class Service {}";
        $documents->open(new Document($phpUri, 'php', 1, $php));
        $phpPosition = $converter->toPosition($php, strpos($php, 'app.a') + \strlen('app.a'));
        $phpResult = $handler->complete([
            'textDocument' => ['uri' => $phpUri],
            'position' => ['line' => $phpPosition->line(), 'character' => $phpPosition->character()],
        ]);
        self::assertIsArray($phpResult);
        self::assertIsArray($phpResult[0]['textEdit']);

        self::assertSame('app.api_key', $phpResult[0]['label'] ?? null);
        self::assertSame('app.api_key', $phpResult[0]['textEdit']['newText'] ?? null);

        $servicePhpUri = 'file:///workspace/src/MailerConsumer.php';
        $servicePhp = "<?php #[Autowire(service: 'app.ma')] final class MailerConsumer {}";
        $documents->open(new Document($servicePhpUri, 'php', 1, $servicePhp));
        $servicePhpPosition = $converter->toPosition(
            $servicePhp,
            strpos($servicePhp, 'app.ma') + \strlen('app.ma'),
        );
        $servicePhpResult = $handler->complete([
            'textDocument' => ['uri' => $servicePhpUri],
            'position' => [
                'line' => $servicePhpPosition->line(),
                'character' => $servicePhpPosition->character(),
            ],
        ]);

        self::assertSame('app.mailer', $servicePhpResult[0]['label'] ?? null);
    }
}
