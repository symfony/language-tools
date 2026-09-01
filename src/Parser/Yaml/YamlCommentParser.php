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
        if ($tree->hasError) {
            $indexed = [];
            foreach ($comments as $comment) {
                $indexed[$comment->startOffset."\0".$comment->endOffset] = $comment;
            }
            foreach ($this->recover($tree, $source) as $comment) {
                $indexed[$comment->startOffset."\0".$comment->endOffset] ??= $comment;
            }
            $comments = array_values($indexed);
        }
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

    /** @return list<SourceComment> */
    private function recover(TreeSitterTree $tree, string $source): array
    {
        $scalarRanges = [];
        foreach (['plain_scalar', 'single_quote_scalar', 'double_quote_scalar', 'block_scalar'] as $type) {
            foreach ($tree->nodesOfType($type) as $node) {
                $scalarRanges[] = [$node->startByte, $node->endByte];
            }
        }

        $comments = [];
        $blockIndent = null;
        preg_match_all('/^.*(?:\R|$)/m', $source, $lines, \PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as [$rawLine, $lineOffset]) {
            $line = rtrim($rawLine, "\r\n");
            $indent = \strlen($line) - \strlen(ltrim($line, " \t"));
            if (null !== $blockIndent) {
                if ('' === trim($line) || $indent > $blockIndent) {
                    continue;
                }
                $blockIndent = null;
            }

            $commentOffset = $this->commentOffset($line);
            $content = null === $commentOffset ? $line : substr($line, 0, $commentOffset);
            if (null !== $commentOffset) {
                $start = $lineOffset + $commentOffset;
                $end = $lineOffset + \strlen($line);
                if (!$this->insideRange($start, $scalarRanges)) {
                    $comments[] = new SourceComment($start, $end, $start + 1, $end, substr($source, $start + 1, $end - $start - 1));
                }
            }
            if ($this->endsWithBlockHeader($content)) {
                $blockIndent = $indent;
            }
        }

        return $comments;
    }

    private function commentOffset(string $line): ?int
    {
        $quote = null;
        $escaped = false;
        for ($index = 0, $length = \strlen($line); $index < $length; ++$index) {
            $character = $line[$index];
            if (null !== $quote) {
                if ('"' === $quote && $escaped) {
                    $escaped = false;
                } elseif ('"' === $quote && '\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    if ("'" === $quote && "'" === ($line[$index + 1] ?? null)) {
                        ++$index;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif ('#' === $character && (0 === $index || ctype_space($line[$index - 1]))) {
                return $index;
            }
        }

        return null;
    }

    private function endsWithBlockHeader(string $line): bool
    {
        return 1 === preg_match('/(?:^|:\s+|-\s+)(?:!(?:<[^>]*>|[^\s]+)\s+)?[|>](?:[+-][1-9]?|[1-9][+-]?)?\s*$/', trim($line));
    }

    /** @param list<array{int, int}> $ranges */
    private function insideRange(int $offset, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }

        return false;
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
