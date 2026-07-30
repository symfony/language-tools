<?php

namespace Symfony\Lsp\Tests\Feature\Route;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Feature\Route\PhpRouteDeclarationExtractor;

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
            }
            PHP;

        $declarations = (new PhpRouteDeclarationExtractor(new PositionConverter()))->extract(
            'file:///workspace/src/ArticleController.php',
            $text,
        );

        self::assertCount(1, $declarations);
        self::assertSame('article_show', $declarations[0]->name());
        self::assertSame('file:///workspace/src/ArticleController.php', $declarations[0]->uri());
        self::assertSame(5, $declarations[0]->range()->start()->line());
        self::assertSame(36, $declarations[0]->range()->start()->character());
    }
}
