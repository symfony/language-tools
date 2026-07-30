<?php

namespace Symfony\Lsp\Tests\Feature\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\DependencyInjection\ProjectServiceSnapshotLoader;
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
        (new ProjectServiceSnapshotLoader($indexes))->load($project, [
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
}
