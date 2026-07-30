<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\PhpRouteDeclarationExtractor;
use Symfony\Lsp\Feature\Route\RouteDeclaration;

final class PhpRouteDeclarationExtractorTest extends TestCase
{
    public function testExtractsNamedRouteAttributesWithSourceRanges(): void
    {
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Routing\Attribute\Route;

            final class ArticleController
            {
                #[Route('/article/{id}', name: 'article_show', methods: ['GET'])]
                public function show(): void
                {
                }

                #[Route('/article/{id}/edit', 'article_edit')]
                public function edit(): void
                {
                }
            }
            PHP;

        $declarations = (new PhpRouteDeclarationExtractor(new PositionConverter()))->extract(
            'file:///workspace/src/ArticleController.php',
            $text,
        );

        self::assertCount(2, $declarations);
        self::assertSame('article_show', $declarations[0]->name());
        self::assertSame('file:///workspace/src/ArticleController.php', $declarations[0]->uri());
        self::assertSame(5, $declarations[0]->range()->start()->line());
        self::assertSame(36, $declarations[0]->range()->start()->character());
        self::assertSame('article_edit', $declarations[1]->name());
    }

    public function testExtractsRoutingConfiguratorAndRouteCollectionDeclarations(): void
    {
        $text = <<<'PHP'
            <?php
            use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
            use Symfony\Component\Routing\Route;
            use Symfony\Component\Routing\RouteCollection;

            return function (RoutingConfigurator $routes): void {
                $routes->add('article_list', '/article');
            };

            $collection = new RouteCollection();
            $collection->add('legacy_article', new Route('/legacy'));
            PHP;

        $declarations = (new PhpRouteDeclarationExtractor(new PositionConverter()))->extract(
            'file:///workspace/config/routes.php',
            $text,
        );

        self::assertSame(['article_list', 'legacy_article'], array_map(
            static fn (RouteDeclaration $declaration): string => $declaration->name(),
            $declarations,
        ));
    }
}
