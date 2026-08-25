<?php

namespace Symfony\Lsp\Tests\Parser\Php;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;

final class PhpStringLiteralDecoderTest extends TestCase
{
    public function testWrapsOverflowingOctalEscapesLikePhp(): void
    {
        $literal = '"\777"';
        $expected = @eval('return '.$literal.';');

        self::assertIsString($expected);
        self::assertSame($expected, PhpStringLiteralDecoder::decodeDoubleQuoted('\777'));
    }

    #[DataProvider('malformedUnicodeEscapeProvider')]
    public function testKeepsMalformedUnicodeEscapesLiteral(string $value): void
    {
        self::assertSame($value, PhpStringLiteralDecoder::decodeDoubleQuoted($value));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedUnicodeEscapeProvider(): iterable
    {
        yield 'empty codepoint' => ['\u{}'];
        yield 'invalid hexadecimal' => ['\u{xyz}'];
        yield 'oversized codepoint' => ['\u{110000}'];
        yield 'missing closing brace' => ['\u{64'];
    }
}
