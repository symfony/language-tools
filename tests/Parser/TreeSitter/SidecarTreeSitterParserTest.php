<?php

namespace Symfony\Lsp\Tests\Parser\TreeSitter;

use PHPUnit\Framework\TestCase;
use Symfony\Lsp\Parser\TreeSitter\SidecarTreeSitterParser;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterResultDecoder;

final class SidecarTreeSitterParserTest extends TestCase
{
    public function testParsesTwigWithThePortableSidecar(): void
    {
        $source = "{{ path('article_show') }}";
        $parser = new SidecarTreeSitterParser(
            \dirname(__DIR__, 3).'/var/build/tree_sitter_cli/symfony-lsp-tree-sitter',
            new TreeSitterResultDecoder(),
        );

        $tree = $parser->parse('twig', $source);

        self::assertFalse($tree->hasError());
        self::assertSame("'article_show'", $tree->text($tree->nodesOfType('string')[0], $source));
    }
}
