<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteCompletionHandler;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class RouteCompletionHandlerTest extends TestCase
{
    public function testCompletesRouteParameters(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('article_show', ['section' => 'news', 's']);
                }
            }
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(
            new Route('article_show', '/{section}/article/{slug}', [], [], null, null),
        );
        $converter = new PositionConverter();
        $cursor = strpos($text, "'s']") + 2;
        $position = $converter->toPosition($text, $cursor);
        $handler = new RouteCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            $indexes,
        );

        self::assertSame(['slug'], array_column($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ]) ?? [], 'label'));
    }

    public function testCompletesRouteNamesInTwigFunctions(): void
    {
        $uri = 'file:///workspace/templates/article.html.twig';
        $text = "{{ path('article_') }}";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'twig', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(
            new Route('article_show', '/article/{id}', [], [], null, null),
            new Route('homepage', '/', [], [], null, null),
        );
        $converter = new PositionConverter();
        $cursor = strpos($text, 'article_') + \strlen('article_');
        $position = $converter->toPosition($text, $cursor);
        $handler = new RouteCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            $indexes,
        );

        self::assertSame(['article_show'], array_column($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ]) ?? [], 'label'));
    }

    public function testCompletesRouteParametersInTwigFunctions(): void
    {
        $uri = 'file:///workspace/templates/article.html.twig';
        $text = "{{ path('article_show', {'section': 'news', 's') }}";
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'twig', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(
            new Route('article_show', '/{section}/article/{slug}', [], [], null, null),
        );
        $converter = new PositionConverter();
        $cursor = strpos($text, "'s')") + 2;
        $position = $converter->toPosition($text, $cursor);
        $handler = new RouteCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            $indexes,
        );

        self::assertSame(['slug'], array_column($handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ]) ?? [], 'label'));
    }

    public function testReturnsRouteCompletionWithUtf16TextEdit(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $label = '😀';
                    $this->generateUrl('article_');
                }
            }
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(
            new Route('article_edit', '/article/{id}/edit', [], [], null, null),
            new Route('homepage', '/', [], [], null, null),
        );
        $converter = new PositionConverter();
        $cursor = strpos($text, 'article_') + \strlen('article_');
        $position = $converter->toPosition($text, $cursor);
        $handler = new RouteCompletionHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            $indexes,
        );

        self::assertSame([[
            'label' => 'article_edit',
            'kind' => 12,
            'detail' => '/article/{id}/edit',
            'textEdit' => [
                'range' => [
                    'start' => ['line' => 6, 'character' => 28],
                    'end' => ['line' => 6, 'character' => 36],
                ],
                'newText' => 'article_edit',
            ],
        ]], $handler->complete([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ]));
    }
}
