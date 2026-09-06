<?php

namespace Symfony\Lsp\Tests\Feature\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Feature\Translation\XliffXmlReferenceDecoder;

final class XliffXmlReferenceDecoderTest extends TestCase
{
    #[DataProvider('referenceProvider')]
    public function testDecodesOnlyValidXmlReferences(string $input, string $expected): void
    {
        self::assertSame($expected, (new XliffXmlReferenceDecoder())->decode($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function referenceProvider(): iterable
    {
        yield 'predefined' => ['&amp;&lt;&gt;&apos;&quot;', '&<>\'"'];
        yield 'numeric' => ['&#65;&#x1F600;', "A\u{1F600}"];
        yield 'numeric boundaries' => ['&#9;&#10;&#13;&#32;&#xD7FF;&#xE000;&#xFFFD;&#x10000;&#x10FFFF;', "\t\n\r \u{D7FF}\u{E000}\u{FFFD}\u{10000}\u{10FFFF}"];
        yield 'single pass' => ['&amp;lt; &amp;#X41;', '&lt; &#X41;'];
        yield 'declared and HTML' => ['&declared; &nbsp;', '&declared; &nbsp;'];
        yield 'invalid code points' => ['&#0; &#xD800; &#xFFFE; &#x110000;', '&#0; &#xD800; &#xFFFE; &#x110000;'];
        yield 'invalid syntax and overflow' => ['&#X41; &#-1; &#99999999999999999999; &amp', '&#X41; &#-1; &#99999999999999999999; &amp'];
    }
}
