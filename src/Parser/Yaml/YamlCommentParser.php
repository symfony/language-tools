<?php

namespace Symfony\Lsp\Parser\Yaml;

use Symfony\Lsp\Parser\CommentParseResult;
use Symfony\Lsp\Parser\CommentParserInterface;
use Symfony\Lsp\Parser\SourceComment;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterTree;

/**
 * Blanks YAML comments while preserving byte offsets and UTF-16 positions.
 *
 * Only ASCII bytes are replaced with spaces: multibyte sequences keep their
 * byte length and UTF-16 unit count, so positions measured on the masked
 * text always match the original document.
 */
final class YamlCommentParser implements CommentParserInterface
{
    private ?string $lastSource = null;
    private ?CommentParseResult $lastResult = null;

    public function __construct(private readonly TreeSitterParserInterface $parser)
    {
    }

    public function parse(string $source): CommentParseResult
    {
        if ($source === $this->lastSource && null !== $this->lastResult) {
            return $this->lastResult;
        }

        $tree = $this->parser->parse('yaml', $source);
        $comments = [];
        $this->collect($tree, $tree->root(), $source, $comments);
        usort($comments, static fn (SourceComment $left, SourceComment $right): int => $left->startOffset <=> $right->startOffset);

        $masked = $source;
        foreach ($comments as $comment) {
            $this->maskRange($masked, $source, $comment->startOffset, $comment->endOffset);
        }
        $this->lastSource = $source;

        return $this->lastResult = new CommentParseResult($masked, $comments);
    }

    public function mask(string $source): string
    {
        return $this->parse($source)->masked;
    }

    public function comments(string $source): array
    {
        return $this->parse($source)->comments;
    }

    /** @param list<SourceComment> $comments */
    private function collect(TreeSitterTree $tree, TreeSitterNode $node, string $source, array &$comments): void
    {
        if ('comment' === $node->type) {
            $comments[] = new SourceComment(
                $node->startByte,
                $node->endByte,
                $node->startByte + 1,
                $node->endByte,
                substr($source, $node->startByte + 1, $node->endByte - $node->startByte - 1),
            );

            return;
        }
        foreach ($tree->children($node) as $child) {
            $this->collect($tree, $child, $source, $comments);
        }
    }

    private function maskRange(string &$masked, string $source, int $start, int $end): void
    {
        for ($offset = $start; $offset < $end; ++$offset) {
            $byte = $source[$offset];
            if ("\r" !== $byte && "\n" !== $byte && \ord($byte) < 0x80) {
                $masked[$offset] = ' ';
            }
        }
    }
}
