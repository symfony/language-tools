<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Route\RouteHoverHandler;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class RouteHoverHandlerTest extends TestCase
{
    public function testDescribesRuntimeRoute(): void
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
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            namespace App\Controller;

            class DemoController extends BaseController
            {
                public function index(): void
                {
                    $this->generateUrl('article_show');
                }
            }
            PHP;
        $kit = (new ProjectTestKit())
            ->open($baseUri, $base)
            ->open($uri, $text)
            ->index()
            ->runtime('routes', ['complete' => true, 'items' => [[
                'name' => 'article_show',
                'path' => '/article/{id}',
                'methods' => ['GET'],
                'schemes' => ['https'],
                'host' => '{subdomain}.example.com',
                'controller' => 'App\\Controller\\ArticleController::show',
                'defaults' => ['locale'],
                'requirements' => ['id' => '\\d+'],
                'alias' => 'article_detail',
            ]]])
        ;

        self::assertSame([
            'contents' => [
                'kind' => 'markdown',
                'value' => "`article_show`\n\nAlias of: `article_detail`\n\nPath: `/article/{id}`\n\nHost: `{subdomain}.example.com`\n\nMethods: `GET`\n\nSchemes: `https`\n\nDefaults: `locale`\n\nRequirements: `id: \\d+`\n\nController: `App\\Controller\\ArticleController::show`",
            ],
        ], $kit->get(RouteHoverHandler::class)->hover($kit->positioned($kit->offset($uri, strpos($text, 'article_show') + 3))));
    }
}
