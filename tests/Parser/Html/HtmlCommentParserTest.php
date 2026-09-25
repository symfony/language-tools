<?php

namespace Symfony\Lsp\Tests\Parser\Html;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Html\HtmlCommentParser;

final class HtmlCommentParserTest extends TestCase
{
    public function testMasksCommentsWhileKeepingSurroundingMarkup(): void
    {
        $comment = '<!-- <twig:Hidden /> -->';
        $source = '<p>a</p>'.$comment.'<p>b</p>';
        $result = (new HtmlCommentParser())->parse($source);

        self::assertSame('<p>a</p>'.str_repeat(' ', \strlen($comment)).'<p>b</p>', $result->masked);
        self::assertSame(
            [[8, 8 + \strlen($comment), ' <twig:Hidden /> ']],
            array_map(
                static fn ($parsed): array => [$parsed->startOffset, $parsed->endOffset, $parsed->content],
                $result->comments,
            ),
        );
    }

    public function testEndsACommentAtItsFirstClosingDelimiter(): void
    {
        $comment = '<!-- <!-- <twig:Hidden /> -->';
        $result = (new HtmlCommentParser())->parse($comment.'<twig:Shown />');

        self::assertSame(str_repeat(' ', \strlen($comment)).'<twig:Shown />', $result->masked);
        self::assertSame(
            [[0, \strlen($comment)]],
            array_map(static fn ($parsed): array => [$parsed->startOffset, $parsed->endOffset], $result->comments),
        );
    }

    public function testMasksUnterminatedCommentsAndPreservesPositions(): void
    {
        $source = "<p>Café</p>\n<!-- caché ✓\n<twig:Hidden />\n";
        $masked = (new HtmlCommentParser())->mask($source);

        self::assertStringStartsWith("<p>Café</p>\n     ", $masked);
        self::assertStringNotContainsString('twig:Hidden', $masked);
        self::assertSame(substr_count($source, "\n"), substr_count($masked, "\n"));
        self::assertSame(\strlen($source), \strlen($masked));
        self::assertSame(
            \strlen(mb_convert_encoding($source, 'UTF-16LE', 'UTF-8')),
            \strlen(mb_convert_encoding($masked, 'UTF-16LE', 'UTF-8')),
        );
    }
}
