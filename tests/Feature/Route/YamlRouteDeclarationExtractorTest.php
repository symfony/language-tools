<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\YamlRouteDeclarationExtractor;

final class YamlRouteDeclarationExtractorTest extends TestCase
{
    public function testExtractsRoutesAndIgnoresImports(): void
    {
        $text = <<<'YAML'
            controllers:
                resource: routing.controllers

            article_show:
                path: /article/{id}
                controller: App\Controller\ArticleController::show

            'article_edit':
                path: /article/{id}/edit
                methods: [GET]
            YAML;

        $declarations = (new YamlRouteDeclarationExtractor(new PositionConverter()))->extract(
            'file:///workspace/config/routes.yaml',
            $text,
        );

        self::assertSame(['article_show', 'article_edit'], array_map(
            static fn (RouteDeclaration $declaration): string => $declaration->name(),
            $declarations,
        ));
        self::assertSame(3, $declarations[0]->range()->start()->line());
        self::assertSame(0, $declarations[0]->range()->start()->character());
        self::assertSame(7, $declarations[1]->range()->start()->line());
        self::assertSame(1, $declarations[1]->range()->start()->character());
    }
}
