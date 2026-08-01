<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\Route;
use Symfony\Lsp\Feature\Route\RouteHoverHandler;
use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class RouteHoverHandlerTest extends TestCase
{
    public function testDescribesRuntimeRoute(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('article_show');
                }
            }
            PHP;
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'php', 1, $text));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $indexes = new RouteIndexRegistry();
        $indexes->forProject($project)->replace(new Route(
            'article_show',
            '/article/{id}',
            ['GET'],
            ['https'],
            '{subdomain}.example.com',
            'App\\Controller\\ArticleController::show',
            ['locale'],
            ['id' => '\\d+'],
        ));
        $converter = new PositionConverter();
        $offset = strpos($text, 'article_show') + 3;
        $position = $converter->toPosition($text, $offset);
        $handler = new RouteHoverHandler(
            new DocumentContextResolver($documents, $projects),
            $converter,
            $indexes,
            new TwigRouteReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
        );

        self::assertSame([
            'contents' => [
                'kind' => 'markdown',
                'value' => "`article_show`\n\nPath: `/article/{id}`\n\nHost: `{subdomain}.example.com`\n\nMethods: `GET`\n\nSchemes: `https`\n\nDefaults: `locale`\n\nRequirements: `id: \\d+`\n\nController: `App\\Controller\\ArticleController::show`",
            ],
        ], $handler->hover([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ]));
    }
}
