<?php

namespace Symfony\Lsp\Tests\Parser\Php;

use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;
use Symfony\Lsp\Parser\Php\TolerantPhpParser;

final class TolerantPhpParserTest extends TestCase
{
    public function testExposesResolvedAttributesAndLiteralSourceOffsets(): void
    {
        $source = <<<'PHP'
            <?php
            use Symfony\Component\Routing\Attribute\Route as RoutingRoute;

            #[RoutingRoute(path: '/article', name: "article_list")]
            final class ArticleController
            {
            }

            $routes->add('article_list', '/article');
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);
        $attribute = $document->attributes()[0];
        $name = $attribute->argument('name')?->stringLiteral();

        self::assertSame('Symfony\Component\Routing\Attribute\Route', $attribute->name());
        self::assertInstanceOf(PhpStringLiteral::class, $name);
        self::assertSame('article_list', $name->value());
        self::assertSame('article_list', substr($source, $name->startOffset(), $name->endOffset() - $name->startOffset()));
        self::assertSame('$routes', $document->methodCalls()[0]->receiver());
        self::assertSame('add', $document->methodCalls()[0]->method());
        self::assertSame('article_list', $document->methodCalls()[0]->argument(0)?->stringLiteral()?->value());
        self::assertSame([], $document->diagnostics());
    }

    public function testRejectsInterpolatedStringsAsLiteralsAndReportsSyntaxDiagnostics(): void
    {
        $source = <<<'PHP'
            <?php
            #[Route(name: "article_$action")]
            final class ArticleController
            {
            PHP;

        $document = (new TolerantPhpParser(new Parser()))->parse($source);

        self::assertNull($document->attributes()[0]->argument('name')?->stringLiteral());
        self::assertCount(1, $document->diagnostics());
        self::assertSame("'}' expected.", $document->diagnostics()[0]->message());
    }
}
