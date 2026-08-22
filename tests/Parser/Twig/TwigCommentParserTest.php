<?php

namespace Symfony\Lsp\Tests\Parser\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;

final class TwigCommentParserTest extends TestCase
{
    private TwigCommentParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TwigCommentParser();
    }

    public function testMasksCommentsPreservingByteLength(): void
    {
        $source = "{# note #}\n{{ trans('key') }}";
        $masked = $this->parser->mask($source);

        self::assertSame("          \n{{ trans('key') }}", $masked);
    }

    public function testPreservesByteOffsetsAndUtf16PositionsForMultibyteComments(): void
    {
        $source = "{# vérifié ✓ #} {{ trans('key') }}";
        $masked = $this->parser->mask($source);

        self::assertStringEndsWith(" {{ trans('key') }}", $masked);
        self::assertSame(\strlen($source), \strlen($masked));
        self::assertSame(
            \strlen(mb_convert_encoding($source, 'UTF-16LE', 'UTF-8')),
            \strlen(mb_convert_encoding($masked, 'UTF-16LE', 'UTF-8')),
        );
    }
}
