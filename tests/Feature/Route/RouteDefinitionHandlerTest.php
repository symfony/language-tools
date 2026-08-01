<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentContextResolver;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Route\PhpRouteDeclarationExtractor;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteDeclarationIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteDefinitionHandler;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\RouteSymbolResolver;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\YamlRouteDeclarationExtractor;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class RouteDefinitionHandlerTest extends TestCase
{
    public function testNavigatesFromRouteReferenceToAttributeName(): void
    {
        $uri = 'file:///workspace/src/ConsumerController.php';
        $text = <<<'PHP'
            <?php
            class ConsumerController extends AbstractController
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
        $declarations = new RouteDeclarationIndexRegistry();
        $declarations->forProject($project)->replace(new RouteDeclaration(
            'article_show',
            'file:///workspace/src/ArticleController.php',
            new Range(new Position(10, 20), new Position(10, 32)),
        ));
        $converter = new PositionConverter();
        $cursor = strpos($text, 'article_show') + 3;
        $position = $converter->toPosition($text, $cursor);
        $handler = new RouteDefinitionHandler(
            new DocumentContextResolver($documents, $projects),
            new RouteSymbolResolver(
                $converter,
                new RouteReferenceExtractor($converter),
                new TwigRouteReferenceExtractor($converter, new TwigDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder()))),
                new PhpRouteDeclarationExtractor($converter, new TolerantPhpParser(new Parser())),
                new YamlRouteDeclarationExtractor($converter),
            ),
            $declarations,
        );

        self::assertSame([[
            'uri' => 'file:///workspace/src/ArticleController.php',
            'range' => [
                'start' => ['line' => 10, 'character' => 20],
                'end' => ['line' => 10, 'character' => 32],
            ],
        ]], $handler->definition([
            'textDocument' => ['uri' => $uri],
            'position' => ['line' => $position->line(), 'character' => $position->character()],
        ]));
    }
}
