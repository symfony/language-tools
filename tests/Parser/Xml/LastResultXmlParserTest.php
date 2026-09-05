<?php

namespace Symfony\Lsp\Tests\Parser\Xml;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\Xml\LastResultXmlParser;
use Symfony\Lsp\Parser\Xml\XmlDocument;
use Symfony\Lsp\Parser\Xml\XmlParserInterface;

final class LastResultXmlParserTest extends TestCase
{
    public function testReusesOnlyTheLastSourceResult(): void
    {
        $inner = new RecordingXmlParser();
        $parser = new LastResultXmlParser($inner);

        $first = $parser->parse('<first/>');
        self::assertSame($first, $parser->parse('<first/>'));
        $second = $parser->parse('<second/>');
        self::assertNotSame($first, $second);
        self::assertNotSame($first, $parser->parse('<first/>'));
        self::assertSame(['<first/>', '<second/>', '<first/>'], $inner->sources);
    }
}

final class RecordingXmlParser implements XmlParserInterface
{
    /** @var list<string> */
    public array $sources = [];

    public function parse(string $source): XmlDocument
    {
        $this->sources[] = $source;

        return new XmlDocument([]);
    }
}
