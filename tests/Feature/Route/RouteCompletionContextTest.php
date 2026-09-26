<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\RouteCompletionContext;
use Symfony\Lsp\Feature\Route\RouteParameterCompletionContext;

final class RouteCompletionContextTest extends TestCase
{
    #[DataProvider('contextProvider')]
    public function testRecognizesRouteNameContexts(string $php, ?string $prefix): void
    {
        $context = $this->completionAt($php);

        self::assertSame($prefix, $context instanceof RouteCompletionContext ? $context->prefix : null);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function contextProvider(): iterable
    {
        yield 'controller helper' => [<<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('article_|');
                }
            }
            PHP, 'article_'];
        yield 'redirection' => [<<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->redirectToRoute('article_|');
                }
            }
            PHP, 'article_'];
        yield 'router' => [<<<'PHP'
            <?php
            use Symfony\Component\Routing\RouterInterface;
            function notify(RouterInterface $router): void
            {
                $router->generate('home|');
            }
            PHP, 'home'];
        yield 'unrelated method' => [<<<'PHP'
            <?php
            use Symfony\Component\Routing\RouterInterface;
            function notify(RouterInterface $router): void
            {
                $router->url('home|');
            }
            PHP, null];
        yield 'static call' => [<<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    self::generateUrl('article_|');
                }
            }
            PHP, null];
        yield 'completed route name' => [<<<'PHP'
            <?php
            use Symfony\Component\Routing\RouterInterface;
            function notify(RouterInterface $router): void
            {
                $router->generate('home')|;
            }
            PHP, null];
        yield 'concatenated route name' => [<<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('article_|' . $suffix);
                }
            }
            PHP, null];
        yield 'unknown receiver' => [<<<'PHP'
            <?php
            $unknown->generateUrl('article_|');
            PHP, null];
        yield 'route name in the parameter array' => [<<<'PHP'
            <?php
            use Symfony\Component\Routing\RouterInterface;
            function notify(RouterInterface $router): void
            {
                $router->generate(['home|']);
            }
            PHP, null];
    }

    public function testRecognizesRouteParameterContexts(): void
    {
        $context = $this->completionAt(<<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('article_show', ['section' => 'news', 'sl|
                }
            }
            PHP);

        self::assertInstanceOf(RouteParameterCompletionContext::class, $context);
        self::assertSame('article_show', $context->routeName);
        self::assertSame('sl', $context->prefix);
        self::assertSame(['section'], $context->existingParameters);
    }

    public function testReadsProvidedParametersOfLegacyArrayCalls(): void
    {
        $context = $this->completionAt(<<<'PHP'
            <?php
            class DemoController extends AbstractController
            {
                public function index(): void
                {
                    $this->generateUrl('article_show', array('section' => 'news', 'sl|
                }
            }
            PHP);

        self::assertInstanceOf(RouteParameterCompletionContext::class, $context);
        self::assertSame(['section'], $context->existingParameters);
    }

    public function testIgnoresParameterValuesAndNestedParameterArrays(): void
    {
        foreach ([
            "\$this->generateUrl('article_show', ['section' => 'ne|", // a value, not a parameter name
            "\$this->generateUrl('article_show', ['filters' => ['se|", // a nested array key
            "\$this->generateUrl('article_show', 'news', ['se|", // the third argument
            "\$this->generateUrl('article_show', compact('se|",
            "\$this->generateUrl('article_show', sprintf('se|",
            "\$this->generateUrl('article_show', \$all ? ['se|",
        ] as $call) {
            self::assertNull($this->completionAt(<<<PHP
                <?php
                class DemoController extends AbstractController
                {
                    public function index(): void
                    {
                        {$call}
                    }
                }
                PHP));
        }
    }

    private function completionAt(string $php): RouteCompletionContext|RouteParameterCompletionContext|null
    {
        $cursor = strpos($php, '|');
        self::assertIsInt($cursor);

        return RouteReferenceExtractorFactory::create(new PositionConverter())->phpCompletionAt(str_replace('|', '', $php), $cursor);
    }
}
