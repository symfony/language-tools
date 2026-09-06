<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Yaml\YamlCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlScalar;
use Symfony\Lsp\Parser\Yaml\YamlScalarStyle;

final class YamlIndentationAnalyzer
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly YamlDocumentParser $parser,
        private readonly YamlCommentParser $comments,
    ) {
    }

    /** @return list<Range> lines whose structural indentation contains a tab */
    public function tabIndentedLines(string $text): array
    {
        if (!str_contains($text, "\t")) {
            return [];
        }
        $document = $this->parser->parseDocument($text);
        $scalars = $document->scalars;
        $keys = [];
        foreach ($document->mappings as $mapping) {
            $keys[$mapping->keyStartByte] = $mapping->keyEndByte;
        }
        $syntax = $this->comments->mask($text);
        $keyEnd = 0;
        $scalarIndex = 0;
        $flowDepth = 0;
        $quote = null;
        $quoteAllowed = true;
        $escaped = false;
        $ranges = [];
        preg_match_all('/^.*(?:\R|$)/m', $text, $lines, \PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as [$rawLine, $lineOffset]) {
            $line = rtrim($rawLine, "\r\n");
            if (0 === $flowDepth && null === $quote) {
                $quoteAllowed = true;
            }
            $indent = strspn($line, " \t");
            if (str_contains(substr($line, 0, $indent), "\t")
                && 0 === $flowDepth
                && ($indent === \strlen($line) || !$this->isScalarContent($text, $scalars, $lineOffset, strspn($line, ' ')))
            ) {
                $ranges[] = $this->converter->toRange($text, $lineOffset, \strlen($line));
            }
            for ($offset = $lineOffset, $end = $lineOffset + \strlen($rawLine); $offset < $end; ++$offset) {
                $keyEnd = $keys[$offset] ?? $keyEnd;
                if ($offset < $keyEnd) {
                    $offset = min($end, $keyEnd) - 1;
                    $quoteAllowed = false;
                    continue;
                }
                while (isset($scalars[$scalarIndex]) && $scalars[$scalarIndex]->endByte <= $offset) {
                    ++$scalarIndex;
                }
                $scalar = $scalars[$scalarIndex] ?? null;
                if (null !== $scalar && $scalar->startByte <= $offset
                    && (0 === $flowDepth || YamlScalarStyle::Plain !== $scalar->style)
                ) {
                    $offset = min($end, $scalar->endByte) - 1;
                    $quoteAllowed = false;
                    continue;
                }
                $character = $syntax[$offset];
                if (null !== $quote) {
                    if ("'" === $quote && "'" === $character && "'" === ($syntax[$offset + 1] ?? null)) {
                        ++$offset;
                    } elseif ($escaped) {
                        $escaped = false;
                    } elseif ('"' === $quote && '\\' === $character) {
                        $escaped = true;
                    } elseif ($quote === $character) {
                        $quote = null;
                    }
                } elseif ($quoteAllowed && \in_array($character, ['"', "'"], true)
                    && (null === $scalar || $scalar->startByte > $offset)
                ) {
                    $quote = $character;
                } elseif ('[' === $character || '{' === $character) {
                    ++$flowDepth;
                } elseif ((']' === $character || '}' === $character) && 0 < $flowDepth) {
                    --$flowDepth;
                }
                if (null === $quote && !ctype_space($character)) {
                    $quoteAllowed = \in_array($character, ['[', '{', ',', ':'], true);
                }
            }
        }

        return $ranges;
    }

    /** @param list<YamlScalar> $scalars */
    private function isScalarContent(string $text, array $scalars, int $lineOffset, int $spaces): bool
    {
        foreach ($scalars as $scalar) {
            if ($scalar->startByte >= $lineOffset || $scalar->endByte <= $lineOffset) {
                continue;
            }
            // quoted scalars continue at their own indentation, while plain and
            // block scalars only continue deeper than their first line
            $header = $this->lineIndent($text, $scalar->startByte);
            $quoted = \in_array($scalar->style, [YamlScalarStyle::SingleQuoted, YamlScalarStyle::DoubleQuoted], true);
            if ($spaces > $header || ($quoted && $spaces === $header)) {
                return true;
            }
        }

        return false;
    }

    private function lineIndent(string $text, int $offset): int
    {
        $lineStart = strrpos(substr($text, 0, $offset), "\n");

        return strspn($text, ' ', false === $lineStart ? 0 : $lineStart + 1);
    }
}
