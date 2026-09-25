<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterTree;

final class RecordingTreeSitterParser implements TreeSitterParserInterface
{
    /** @var list<array{string, string}> */
    public array $calls = [];

    public function __construct(private readonly ?TreeSitterParserInterface $parser = null)
    {
    }

    public function parse(string $language, string $source): TreeSitterTree
    {
        $this->calls[] = [$language, $source];

        return $this->parser?->parse($language, $source) ?? new TreeSitterTree(false, []);
    }
}
