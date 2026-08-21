<?php

namespace Symfony\Lsp\Tests\Parser\TreeSitter;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\TreeSitter\LastResultTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterTree;

final class LastResultTreeSitterParserTest extends TestCase
{
    public function testReusesOnlyTheLastLanguageAndSourceResult(): void
    {
        $inner = new RecordingTreeSitterParser();
        $parser = new LastResultTreeSitterParser($inner);

        $yaml = $parser->parse('yaml', 'same source');
        self::assertSame($yaml, $parser->parse('yaml', 'same source'));
        $twig = $parser->parse('twig', 'same source');
        self::assertNotSame($yaml, $twig);
        self::assertSame($twig, $parser->parse('twig', 'same source'));
        self::assertNotSame($yaml, $parser->parse('yaml', 'same source'));
        self::assertSame([
            ['yaml', 'same source'],
            ['twig', 'same source'],
            ['yaml', 'same source'],
        ], $inner->calls);
    }
}

final class RecordingTreeSitterParser implements TreeSitterParserInterface
{
    /** @var list<array{string, string}> */
    public array $calls = [];

    public function parse(string $language, string $source): TreeSitterTree
    {
        $this->calls[] = [$language, $source];

        return new TreeSitterTree(false, []);
    }
}
