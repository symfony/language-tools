<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\RouteCompletionContext;

final class RouteCompletionContextTest extends TestCase
{
    #[DataProvider('contextProvider')]
    public function testRecognizesHighConfidenceRouteContexts(string $php, string $prefix): void
    {
        $cursor = strpos($php, '|');
        self::assertIsInt($cursor);
        $php = str_replace('|', '', $php);
        $converter = new PositionConverter();

        $context = RouteCompletionContext::fromPhp(
            $php,
            $converter->toPosition($php, $cursor),
            $converter,
        );

        self::assertSame($prefix, $context?->prefix());
    }

    /**
     * @return iterable<string, array{string, string}>
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
        yield 'typed router' => [<<<'PHP'
            <?php
            function run(RouterInterface $router): void
            {
                $router->generateUrl('home|');
            }
            PHP, 'home'];
        yield 'typed URL generator' => [<<<'PHP'
            <?php
            function run(UrlGeneratorInterface $generator): void
            {
                $generator->generate('home|');
            }
            PHP, 'home'];
        yield 'typed router property' => [<<<'PHP'
            <?php
            final class Generator
            {
                private RouterInterface $router;

                public function run(): void
                {
                    $this->router->generate('home|');
                }
            }
            PHP, 'home'];
        yield 'promoted URL generator property' => [<<<'PHP'
            <?php
            final class Generator
            {
                public function __construct(private UrlGeneratorInterface $generator) {}

                public function run(): void
                {
                    $this->generator->generate('home|');
                }
            }
            PHP, 'home'];
    }

    public function testRejectsGenericMethodNameOnUnknownReceiver(): void
    {
        $php = <<<'PHP'
            <?php
            $unknown->generateUrl('home');
            PHP;
        $converter = new PositionConverter();

        $cursor = strpos($php, "')");
        self::assertIsInt($cursor);

        self::assertNull(RouteCompletionContext::fromPhp(
            $php,
            $converter->toPosition($php, $cursor),
            $converter,
        ));
    }
}
