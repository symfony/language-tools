<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Route\RouteCompletionHandler;
use Symfony\Lsp\Tests\Support\ProjectTestKit;

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
        $kit = $this->kit($uri, $text, [self::route('article_show', '/{section}/article/{slug}')]);
        $params = $kit->offset($uri, strpos($text, "'s']") + 2);

        self::assertSame(['slug'], $kit->labels($kit->get(RouteCompletionHandler::class)->complete($kit->positioned($params))));
    }

    public function testCompletesParametersFromAllInternationalizedRouteVariants(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('app_home', ['locale_']);
                }
            }
            PHP;
        $kit = $this->kit($uri, $text, [self::route('app_home.en', '/en/{locale_en}', 'app_home'), self::route('app_home.fr', '/fr/{locale_fr}', 'app_home')]);
        $params = $kit->offset($uri, strpos($text, "locale_']") + \strlen('locale_'));

        self::assertSame(['locale_en', 'locale_fr'], $kit->labels($kit->get(RouteCompletionHandler::class)->complete($kit->positioned($params))));
    }

    #[DataProvider('twigRouteNameCompletionProvider')]
    public function testCompletesRouteNamesInTwigFunctions(string $text): void
    {
        $uri = 'file:///workspace/templates/article.html.twig';
        $kit = $this->kit($uri, $text, [self::route('article_show', '/article/{id}'), self::route('homepage', '/')]);
        $params = $kit->offset($uri, strpos($text, 'article_') + \strlen('article_'));

        self::assertSame(['article_show'], $kit->labels($kit->get(RouteCompletionHandler::class)->complete($kit->positioned($params))));
    }

    public function testIgnoresRouteFunctionTextOutsideTwigDirectives(): void
    {
        $uri = 'file:///workspace/templates/article.html.twig';
        $text = "<p>Call path('article_";
        $kit = $this->kit($uri, $text, [self::route('article_show', '/article/{id}')]);
        $params = $kit->offset($uri, \strlen($text));

        self::assertSame([], $kit->get(RouteCompletionHandler::class)->complete($kit->positioned($params)));
    }

    #[DataProvider('twigRouteParameterCompletionProvider')]
    public function testCompletesRouteParametersInTwigFunctions(string $text): void
    {
        $uri = 'file:///workspace/templates/article.html.twig';
        $kit = $this->kit($uri, $text, [self::route('article_show', '/{section}/article/{slug}')]);
        $params = $kit->offset($uri, strpos($text, "'s')") + 2);

        self::assertSame(['slug'], $kit->labels($kit->get(RouteCompletionHandler::class)->complete($kit->positioned($params))));
    }

    public function testCompletesRoutesThroughAProjectControllerBaseClass(): void
    {
        $baseUri = 'file:///workspace/src/Controller/BaseController.php';
        $base = <<<'PHP'
            <?php
            namespace App\Controller;

            use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

            abstract class BaseController extends AbstractController
            {
            }
            PHP;
        $uri = 'file:///workspace/src/Controller/DemoController.php';
        $text = <<<'PHP'
            <?php
            namespace App\Controller;

            final class DemoController extends BaseController
            {
                public function index(): void
                {
                    $this->redirectToRoute('article_');
                }
            }
            PHP;
        $kit = $this->kit($uri, $text, [self::route('article_show', '/article/{id}')])->open($baseUri, $base)->index();
        $params = $kit->offset($uri, strpos($text, 'article_') + \strlen('article_'));

        self::assertSame(['article_show'], $kit->labels($kit->get(RouteCompletionHandler::class)->complete($kit->positioned($params))));
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
        $kit = $this->kit($uri, $text, [self::route('article_edit', '/article/{id}/edit'), self::route('homepage', '/')]);
        $params = $kit->offset($uri, strpos($text, 'article_') + \strlen('article_'));

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
        ]], $kit->get(RouteCompletionHandler::class)->complete($kit->positioned($params)));
    }

    public function testOffersNoRouteCompletionsInsideTwigComments(): void
    {
        $uri = 'file:///workspace/templates/article.html.twig';
        foreach (["{# {{ path('artic') }} #}", "{# {{ path('article_show', {'s') }} #}"] as $text) {
            $kit = $this->kit($uri, $text, [self::route('article_show', '/article/{slug}')]);
            $cursor = strpos($text, "')");
            self::assertIsInt($cursor);
            $params = $kit->offset($uri, $cursor);

            self::assertSame([], $kit->get(RouteCompletionHandler::class)->complete($kit->positioned($params)));
        }
    }

    public function testCompletesRoutesOnSymfonyRouterReceiversBeingTyped(): void
    {
        $uri = 'file:///workspace/src/Notifier.php';
        $text = <<<'PHP'
            <?php
            namespace App\Service;

            use Symfony\Component\Routing\RouterInterface;

            final class Notifier
            {
                public function __construct(private readonly RouterInterface $router)
                {
                }

                public function notify(): string
                {
                    return $this->router->generate('article_
                }
            }
            PHP;
        $kit = $this->kit($uri, $text, [self::route('article_show', '/article/{slug}')]);
        $params = $kit->offset($uri, strpos($text, 'article_') + \strlen('article_'));

        self::assertSame(['article_show'], $kit->labels($kit->get(RouteCompletionHandler::class)->complete($kit->positioned($params))));
    }

    public function testOffersNoRouteCompletionsOnUnrelatedRouterTypes(): void
    {
        $uri = 'file:///workspace/src/Notifier.php';
        $text = <<<'PHP'
            <?php
            namespace App\Service;

            use App\Routing\MyRouterInterface;

            final class Notifier
            {
                public function notify(MyRouterInterface $router): string
                {
                    return $router->generate('article_');
                }
            }
            PHP;
        $kit = $this->kit($uri, $text, [self::route('article_show', '/article/{slug}')]);
        $params = $kit->offset($uri, strpos($text, 'article_') + \strlen('article_'));

        self::assertSame([], $kit->get(RouteCompletionHandler::class)->complete($kit->positioned($params)));
    }

    public function testOffersNoRouteCompletionsInsidePhpComments(): void
    {
        $uri = 'file:///workspace/src/Controller.php';
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Routing\RouterInterface;
            class Demo
            {
                public function index(RouterInterface $router): void
                {
                    // $router->generate('artic
                }
            }
            PHP;
        $kit = $this->kit($uri, $text, [self::route('article_show', '/article/{slug}')]);
        $params = $kit->offset($uri, strpos($text, 'artic') + \strlen('artic'));

        self::assertSame([], $kit->get(RouteCompletionHandler::class)->complete($kit->positioned($params)));
    }

    /** @return iterable<string, array{string}> */
    public static function twigRouteNameCompletionProvider(): iterable
    {
        yield 'positional' => ["{{ path('article_') }}"];
        yield 'named' => ["{{ path(name: 'article_') }}"];
    }

    /** @return iterable<string, array{string}> */
    public static function twigRouteParameterCompletionProvider(): iterable
    {
        yield 'positional' => ["{{ path('article_show', {'section': 'news', 's') }}"];
        yield 'named' => ["{{ path(name = 'article_show', parameters = {'section': 'news', 's') }}"];
    }

    /** @param list<array<string, string>> $routes */
    private function kit(string $uri, string $text, array $routes): ProjectTestKit
    {
        return (new ProjectTestKit())->open($uri, $text)->runtime('routes', ['complete' => true, 'items' => $routes]);
    }

    /** @return array<string, string> */
    private static function route(string $name, string $path, ?string $canonicalName = null): array
    {
        return ['name' => $name, 'path' => $path] + (null === $canonicalName ? [] : ['canonical' => $canonicalName]);
    }
}
