<?php

namespace Symfony\Lsp\Tests\Parser\Xml;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Xml\XmlCommentParser;

final class XmlCommentParserTest extends TestCase
{
    public function testMasksCommentsContainingQuotesAndAngleBrackets(): void
    {
        $source = <<<'XML'
            <container>
                <!-- "<service id='ignored'>" -->
                <service id="real"/>
            </container>
            XML;

        $comment = '    <!-- "<service id=\'ignored\'>" -->';
        self::assertSame(
            "<container>\n".str_repeat(' ', \strlen($comment))."\n    <service id=\"real\"/>\n</container>",
            (new XmlCommentParser())->mask($source),
        );
    }

    public function testMasksUnterminatedCommentsAndPreservesPositions(): void
    {
        $source = "<container>\n<!-- vérifié ✓\n<ignored/>\n";
        $masked = (new XmlCommentParser())->mask($source);

        self::assertStringStartsWith("<container>\n     ", $masked);
        self::assertSame(\strlen($source), \strlen($masked));
        self::assertSame(
            \strlen(mb_convert_encoding($source, 'UTF-16LE', 'UTF-8')),
            \strlen(mb_convert_encoding($masked, 'UTF-16LE', 'UTF-8')),
        );
        self::assertStringNotContainsString('<ignored/>', $masked);
    }
}
