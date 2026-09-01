<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\RouteDeclaration;
use Symfony\Lsp\Feature\Route\YamlRouteDeclarationExtractor;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\TreeSitter\NativeTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;

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

        $declarations = $this->extract($text);

        self::assertSame(['article_show', 'article_edit'], array_map(
            static fn (RouteDeclaration $declaration): string => $declaration->name,
            $declarations,
        ));
        self::assertSame(3, $declarations[0]->range->start->line);
        self::assertSame(0, $declarations[0]->range->start->character);
        self::assertSame(7, $declarations[1]->range->start->line);
        self::assertSame(1, $declarations[1]->range->start->character);
    }

    public function testExtractsQuotedRouteNamesWithoutQuotesInTheirRanges(): void
    {
        $declarations = $this->extract(<<<'YAML'
            'article_show':
                path: /article/{id}

            "article_edit":
                controller: App\Controller\ArticleController::edit
            YAML);

        self::assertSame(['article_show', 'article_edit'], array_map(
            static fn (RouteDeclaration $declaration): string => $declaration->name,
            $declarations,
        ));
        self::assertSame(1, $declarations[0]->range->start->character);
        self::assertSame(13, $declarations[0]->range->end->character);
        self::assertSame(1, $declarations[1]->range->start->character);
        self::assertSame(13, $declarations[1]->range->end->character);
    }

    public function testExtractsActualRouteNamesFromEnvironmentSections(): void
    {
        $declarations = $this->extract(<<<'YAML'
            when@test:
                article_show:
                    path: /article/{id}
                'article_edit':
                    controller: App\Controller\ArticleController::edit
            YAML);

        self::assertSame(['article_show', 'article_edit'], array_map(
            static fn (RouteDeclaration $declaration): string => $declaration->name,
            $declarations,
        ));
        self::assertSame(1, $declarations[0]->range->start->line);
        self::assertSame(4, $declarations[0]->range->start->character);
        self::assertSame(16, $declarations[0]->range->end->character);
        self::assertSame(3, $declarations[1]->range->start->line);
        self::assertSame(5, $declarations[1]->range->start->character);
        self::assertSame(17, $declarations[1]->range->end->character);
    }

    public function testExtractsRouteMixedWithNonRouteMappings(): void
    {
        $declarations = $this->extract(<<<'YAML'
            services:
                App\Controller\ArticleController: ~

            article_show:
                path: /article/{id}

            controllers:
                resource: ../src/Controller/
                type: attribute
            YAML);

        self::assertSame(['article_show'], array_map(
            static fn (RouteDeclaration $declaration): string => $declaration->name,
            $declarations,
        ));
    }

    public function testExtractsRouteFromMalformedDocument(): void
    {
        $declarations = $this->extract(<<<'YAML'
            article_show:
                path: /article/{id}

            broken: [
            YAML);

        self::assertCount(1, $declarations);
        self::assertSame('article_show', $declarations[0]->name);
        self::assertSame(0, $declarations[0]->range->start->line);
        self::assertSame(0, $declarations[0]->range->start->character);
        self::assertSame(12, $declarations[0]->range->end->character);
    }

    /** @return list<RouteDeclaration> */
    private function extract(string $text): array
    {
        return (new YamlRouteDeclarationExtractor(
            new PositionConverter(),
            new YamlDocumentParser(new NativeTreeSitterParser(new TreeSitterResultDecoder())),
        ))->extract(new SourceDocument(
            'file:///workspace/config/routes.yaml',
            'yaml',
            $text,
        ));
    }
}
