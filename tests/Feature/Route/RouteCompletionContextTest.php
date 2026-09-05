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
        $cursor = strpos($php, '|');
        self::assertIsInt($cursor);
        $php = str_replace('|', '', $php);
        $converter = new PositionConverter();

        $context = RouteCompletionContext::fromPhp($php, $converter->toPosition($php, $cursor), $converter);

        self::assertSame($prefix, $context?->prefix);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function contextProvider(): iterable
    {
        yield 'controller helper' => [<<<'PHP'
            <?php
            $this->generateUrl('article_|');
            PHP, 'article_'];
        yield 'redirection' => [<<<'PHP'
            <?php
            $this->redirectToRoute('article_|');
            PHP, 'article_'];
        yield 'router' => [<<<'PHP'
            <?php
            $router->generate('home|');
            PHP, 'home'];
        yield 'unrelated method' => [<<<'PHP'
            <?php
            $router->url('home|');
            PHP, null];
        yield 'completed route name' => [<<<'PHP'
            <?php
            $router->generate('home')|;
            PHP, null];
    }

    public function testRecognizesRouteParameterContexts(): void
    {
        $php = <<<'PHP'
            <?php
            $this->generateUrl('article_show', ['section' => 'news', 'sl
            PHP;
        $converter = new PositionConverter();
        $cursor = strpos($php, "'sl");
        self::assertIsInt($cursor);

        $context = RouteParameterCompletionContext::fromPhp($php, $converter->toPosition($php, $cursor + 3), $converter);

        self::assertNotNull($context);
        self::assertSame('article_show', $context->routeName);
        self::assertSame('sl', $context->prefix);
        self::assertSame(['section'], $context->existingParameters);
    }
}
