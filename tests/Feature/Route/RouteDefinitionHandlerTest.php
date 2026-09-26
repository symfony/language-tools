<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Route\RouteDefinitionHandler;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class RouteDefinitionHandlerTest extends TestCase
{
    public function testNavigatesFromRouteReferenceToAttributeName(): void
    {
        $baseUri = 'file:///workspace/src/BaseController.php';
        $base = <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            abstract class BaseController extends AbstractController
            {
            }
            PHP;
        $uri = 'file:///workspace/src/ConsumerController.php';
        $text = <<<'PHP'
            <?php
            namespace App\Controller;

            class ConsumerController extends BaseController
            {
                public function index(): void
                {
                    $this->generateUrl('article_show');
                }
            }
            PHP;
        $declarationUri = 'file:///workspace/src/ArticleController.php';
        $declaration = <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Component\Routing\Attribute\Route;

            final class ArticleController
            {
                #[Route('/article/{id}', name: 'article_show')]
                public function show(): void
                {
                }
            }
            PHP;
        $kit = (new ProjectTestKit())
            ->open($baseUri, $base)
            ->open($uri, $text)
            ->open($declarationUri, $declaration)
            ->index()
        ;

        self::assertSame([[
            'uri' => $declarationUri,
            'range' => [
                'start' => $kit->at($declarationUri, 'article_show')['position'],
                'end' => $kit->after($declarationUri, 'article_show')['position'],
            ],
        ]], $kit->get(RouteDefinitionHandler::class)->definition($kit->positioned($kit->offset($uri, strpos($text, 'article_show') + 3))));
    }
}
