<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Route\RouteReferencesHandler;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

final class RouteReferencesHandlerTest extends TestCase
{
    public function testFindsReferencesFromRouteDeclaration(): void
    {
        $uri = 'file:///workspace/src/ArticleController.php';
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Routing\Attribute\Route;
            final class ArticleController { #[Route('/article', name: 'article_list')] public function list(): void {} }
            PHP;
        $baseUri = 'file:///workspace/src/BaseController.php';
        $base = '<?php namespace App\Controller; use Symfony\Bundle\FrameworkBundle\Controller\AbstractController; abstract class BaseController extends AbstractController {}';
        $consumerUri = 'file:///workspace/src/Navigation.php';
        $consumer = "<?php namespace App\\Controller; final class DemoController extends BaseController {\n    public function index(): void { \$this->generateUrl('article_list'); }\n}";
        $kit = (new ProjectTestKit())
            ->open($uri, $text)
            ->open($baseUri, $base)
            ->open($consumerUri, $consumer)
            ->index()
        ;

        self::assertSame([
            [
                'uri' => $consumerUri,
                'range' => ['start' => $kit->at($consumerUri, 'article_list')['position'], 'end' => $kit->after($consumerUri, 'article_list')['position']],
            ],
            [
                'uri' => $uri,
                'range' => ['start' => $kit->at($uri, 'article_list')['position'], 'end' => $kit->after($uri, 'article_list')['position']],
            ],
        ], $kit->get(RouteReferencesHandler::class)->references($kit->references($kit->inside($uri, 'article_list'))));
    }
}
