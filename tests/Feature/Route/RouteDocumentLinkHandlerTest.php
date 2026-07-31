<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\RouteDeclarationIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteDocumentLinkHandler;
use Symfony\Lsp\Feature\Route\RouteReferenceExtractor;
use Symfony\Lsp\Feature\Route\TwigRouteReferenceExtractor;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class RouteDocumentLinkHandlerTest extends TestCase
{
    public function testLinksTwigRouteReferencesToTheirDeclaration(): void
    {
        $uri = 'file:///workspace/templates/navigation.html.twig';
        $documents = new DocumentStore();
        $documents->open(new Document($uri, 'twig', 1, "{{ path('article_show') }}"));
        $projects = new ProjectRegistry();
        $projects->replace([$project = new Project('/workspace', 'file:///workspace', '^8.0')]);
        $declarations = new RouteDeclarationIndexRegistry();
        $declarations->forProject($project)->replace(new RouteDeclaration(
            'article_show',
            'file:///workspace/config/routes.yaml',
            new Range(new Position(4, 0), new Position(4, 12)),
        ));
        $positionConverter = new PositionConverter();
        $handler = new RouteDocumentLinkHandler(
            $documents,
            $projects,
            $declarations,
            new RouteReferenceExtractor($positionConverter),
            new TwigRouteReferenceExtractor($positionConverter, new TwigDocumentParser(new NativeTreeSitterParser())),
        );

        self::assertSame([[
            'range' => [
                'start' => ['line' => 0, 'character' => 9],
                'end' => ['line' => 0, 'character' => 21],
            ],
            'target' => 'file:///workspace/config/routes.yaml#L5',
            'tooltip' => 'Open route "article_show"',
        ]], $handler->links(['textDocument' => ['uri' => $uri]]));
    }
}
