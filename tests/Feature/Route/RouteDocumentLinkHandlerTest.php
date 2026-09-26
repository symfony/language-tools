<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Route\RouteDocumentLinkHandler;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class RouteDocumentLinkHandlerTest extends TestCase
{
    public function testLinksTwigRouteReferencesToTheirDeclaration(): void
    {
        $uri = 'file:///workspace/templates/navigation.html.twig';
        $kit = (new ProjectTestKit())
            ->open($uri, "{{ path('article_show') }}")
            ->open('file:///workspace/config/routes.yaml', <<<'YAML'
                homepage:
                    path: /

                # Articles
                article_show:
                    path: /article/{id}
                YAML)
            ->index()
        ;

        self::assertSame([[
            'range' => [
                'start' => ['line' => 0, 'character' => 9],
                'end' => ['line' => 0, 'character' => 21],
            ],
            'target' => 'file:///workspace/config/routes.yaml#L5',
            'tooltip' => 'Open route "article_show"',
        ]], $kit->get(RouteDocumentLinkHandler::class)->links($kit->document($uri)));
    }
}
