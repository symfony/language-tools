<?php

namespace Symfony\Lsp\Parser\Yaml;

final class YamlRecoveryParser
{
    public function __construct(
        private readonly YamlScalarDecoder $decoder = new YamlScalarDecoder(),
    ) {
    }

    public function parse(string $source): YamlDocument
    {
        $mappings = [];
        $scalars = [];
        /** @var array<int, array{path: list<string>, sequence: list<YamlSequenceItem>, scope: string}> $stack */
        $stack = [];
        /** @var array<string, int> $sequenceIndexes */
        $sequenceIndexes = [];
        /** @var array{start: int, end: int, indent: int, path: list<string>, sequence: list<YamlSequenceItem>, scope: string, tag: string|null, tagStart: int|null, tagEnd: int|null}|null $block */
        $block = null;

        preg_match_all('/^.*(?:\R|$)/m', $source, $lines, \PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as [$rawLine, $lineOffset]) {
            $line = rtrim($rawLine, "\r\n");
            $indent = \strlen($line) - \strlen(ltrim($line, " \t"));
            if (null !== $block) {
                if ('' === trim($line) || $indent > $block['indent']) {
                    $block['end'] = $lineOffset + \strlen($rawLine);
                    continue;
                }
                $scalars[] = $this->recoveredBlockScalar($source, $block);
                $block = null;
            }
            $parsed = $this->fallbackLine($line);
            if (null === $parsed) {
                continue;
            }
            foreach (array_keys($stack) as $level) {
                if ($level >= $parsed['indent']) {
                    unset($stack[$level]);
                }
            }
            foreach (array_keys($sequenceIndexes) as $key) {
                [$level] = explode("\0", $key, 2);
                if ((int) $level > $parsed['indent'] || (!$parsed['sequence'] && (int) $level >= $parsed['indent'])) {
                    unset($sequenceIndexes[$key]);
                }
            }
            ksort($stack);
            $parent = [];
            $sequence = [];
            $scope = 'base';
            foreach ($stack as $entry) {
                $parent = $entry['path'];
                $sequence = $entry['sequence'];
                $scope = $entry['scope'];
            }
            if ($parsed['sequence']) {
                $sequenceKey = $parsed['indent']."\0".$scope."\0".implode("\0", $parent);
                $index = $sequenceIndexes[$sequenceKey] ?? 0;
                $sequenceIndexes[$sequenceKey] = $index + 1;
                $sequence = [...$sequence, new YamlSequenceItem(\count($parent), $index)];
            }

            if (null === $parsed['key']) {
                $scalar = $this->fallbackScalar($lineOffset + $parsed['valueOffset'], $parsed['value'], $parent, $sequence, $scope);
                if (null !== $scalar && \in_array($scalar->style, [YamlScalarStyle::BlockLiteral, YamlScalarStyle::BlockFolded], true)) {
                    $block = [
                        'start' => $scalar->startByte,
                        'end' => $scalar->endByte,
                        'indent' => $parsed['indent'],
                        'path' => $parent,
                        'sequence' => $sequence,
                        'scope' => $scope,
                        'tag' => $scalar->tag,
                        'tagStart' => $scalar->tagStartByte,
                        'tagEnd' => $scalar->tagEndByte,
                    ];
                } elseif (null !== $scalar) {
                    $scalars[] = $scalar;
                }
                continue;
            }

            $environmentSection = str_starts_with($parsed['key'], 'when@');
            if ($environmentSection) {
                $scope = $parsed['key'];
            }
            $path = $environmentSection ? $parent : [...$parent, $parsed['key']];
            $mappingValue = $this->fallbackMappingValue($parsed['value']);
            $value = $this->fallbackScalar($lineOffset + $parsed['valueOffset'], $parsed['value'], $path, $sequence, $scope);
            if ($environmentSection || '' === $mappingValue) {
                $stack[$parsed['indent']] = ['path' => $path, 'sequence' => $sequence, 'scope' => $scope];
            } elseif ($parsed['sequence']) {
                $stack[$parsed['indent']] = ['path' => $parent, 'sequence' => $sequence, 'scope' => $scope];
            }
            if ($environmentSection) {
                continue;
            }

            $keyStart = $lineOffset + $parsed['keyOffset'];
            $valueStart = $lineOffset + $parsed['valueOffset'];
            $mappings[] = new YamlMapping(
                $path,
                $mappingValue,
                $keyStart,
                $keyStart + $parsed['keyLength'],
                $valueStart,
                $valueStart + \strlen($mappingValue),
                array_values(array_unique(array_map(static fn (YamlSequenceItem $item): int => $item->pathDepth, $sequence))),
                $scope,
            );
            if (null !== $value) {
                $scalars[] = $value;
                if (\in_array($value->style, [YamlScalarStyle::BlockLiteral, YamlScalarStyle::BlockFolded], true)) {
                    array_pop($scalars);
                    $block = [
                        'start' => $value->startByte,
                        'end' => $value->endByte,
                        'indent' => $parsed['indent'],
                        'path' => $path,
                        'sequence' => $sequence,
                        'scope' => $scope,
                        'tag' => $value->tag,
                        'tagStart' => $value->tagStartByte,
                        'tagEnd' => $value->tagEndByte,
                    ];
                }
            } elseif (str_starts_with($parsed['value'], '[')) {
                array_push($scalars, ...$this->fallbackFlowSequenceScalars($lineOffset + $parsed['valueOffset'], $parsed['value'], $path, $sequence, $scope));
            }
        }
        if (null !== $block) {
            $scalars[] = $this->recoveredBlockScalar($source, $block);
        }

        usort($mappings, static fn (YamlMapping $left, YamlMapping $right): int => $left->keyStartByte <=> $right->keyStartByte);
        usort($scalars, static fn (YamlScalar $left, YamlScalar $right): int => $left->startByte <=> $right->startByte);

        return new YamlDocument($mappings, $scalars);
    }

    /**
     * @return array{indent: int, sequence: bool, key: string|null, keyOffset: int, keyLength: int, value: string, valueOffset: int}|null
     */
    private function fallbackLine(string $line): ?array
    {
        if ('' === trim($line) || str_starts_with(ltrim($line), '#')) {
            return null;
        }
        $indent = \strlen($line) - \strlen(ltrim($line, " \t"));
        $offset = $indent;
        $sequence = '-' === ($line[$offset] ?? null) && ctype_space($line[$offset + 1] ?? '');
        if ($sequence) {
            ++$offset;
            while (isset($line[$offset]) && \in_array($line[$offset], [' ', "\t"], true)) {
                ++$offset;
            }
        }
        $separator = $this->mappingSeparator($line, $offset);
        if (null === $separator) {
            if (!$sequence) {
                return null;
            }

            return ['indent' => $indent, 'sequence' => true, 'key' => null, 'keyOffset' => $offset, 'keyLength' => 0, 'value' => substr($line, $offset), 'valueOffset' => $offset];
        }

        $rawKey = rtrim(substr($line, $offset, $separator - $offset));
        $keyOffset = $offset;
        $keyLength = \strlen($rawKey);
        if (2 <= $keyLength && \in_array($rawKey[0], ["'", '"'], true) && str_ends_with($rawKey, $rawKey[0])) {
            ++$keyOffset;
            $keyLength -= 2;
            $key = "'" === $rawKey[0] ? str_replace("''", "'", substr($rawKey, 1, -1)) : $this->decoder->decode($rawKey, YamlScalarStyle::DoubleQuoted);
        } else {
            $key = trim($rawKey);
            $leading = \strlen($rawKey) - \strlen(ltrim($rawKey));
            $keyOffset += $leading;
            $keyLength = \strlen($key);
        }
        $valueOffset = $separator + 1;
        while (isset($line[$valueOffset]) && \in_array($line[$valueOffset], [' ', "\t"], true)) {
            ++$valueOffset;
        }

        return [
            'indent' => $indent,
            'sequence' => $sequence,
            'key' => $key,
            'keyOffset' => $keyOffset,
            'keyLength' => $keyLength,
            'value' => substr($line, $valueOffset),
            'valueOffset' => $valueOffset,
        ];
    }

    private function mappingSeparator(string $line, int $offset): ?int
    {
        $quote = null;
        $escaped = false;
        for ($length = \strlen($line); $offset < $length; ++$offset) {
            $character = $line[$offset];
            if (null !== $quote) {
                if ('"' === $quote && $escaped) {
                    $escaped = false;
                } elseif ('"' === $quote && '\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    if ("'" === $quote && "'" === ($line[$offset + 1] ?? null)) {
                        ++$offset;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif (':' === $character && ('' === ($line[$offset + 1] ?? '') || ctype_space($line[$offset + 1]) || \in_array($line[$offset + 1], ['[', '{'], true))) {
                return $offset;
            }
        }

        return null;
    }

    private function fallbackMappingValue(string $value): string
    {
        $quote = null;
        $escaped = false;
        for ($index = 0, $length = \strlen($value); $index < $length; ++$index) {
            $character = $value[$index];
            if (null !== $quote) {
                if ('"' === $quote && $escaped) {
                    $escaped = false;
                } elseif ('"' === $quote && '\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    if ("'" === $quote && "'" === ($value[$index + 1] ?? null)) {
                        ++$index;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif ('#' === $character && (0 === $index || ctype_space($value[$index - 1]))) {
                return rtrim(substr($value, 0, $index));
            }
        }

        return rtrim($value);
    }

    /**
     * @param list<string>           $path
     * @param list<YamlSequenceItem> $sequence
     *
     * @return list<YamlScalar>
     */
    private function fallbackFlowSequenceScalars(int $start, string $fragment, array $path, array $sequence, string $scope): array
    {
        $scalars = [];
        $itemStart = 1;
        $quote = null;
        $escaped = false;
        $index = 0;
        for ($offset = 1, $length = \strlen($fragment); $offset <= $length; ++$offset) {
            $character = $fragment[$offset] ?? ']';
            if (null !== $quote) {
                if ('"' === $quote && $escaped) {
                    $escaped = false;
                } elseif ('"' === $quote && '\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    if ("'" === $quote && "'" === ($fragment[$offset + 1] ?? null)) {
                        ++$offset;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
                continue;
            }
            if (!\in_array($character, [',', ']'], true)) {
                continue;
            }
            $rawItem = substr($fragment, $itemStart, $offset - $itemStart);
            $leading = \strlen($rawItem) - \strlen(ltrim($rawItem, " \t"));
            $item = ltrim($rawItem, " \t");
            $itemSequence = [...$sequence, new YamlSequenceItem(\count($path), $index++)];
            $scalar = $this->fallbackScalar($start + $itemStart + $leading, $item, $path, $itemSequence, $scope);
            if (null !== $scalar) {
                $scalars[] = $scalar;
            }
            $itemStart = $offset + 1;
            if (']' === $character) {
                break;
            }
        }

        return $scalars;
    }

    /**
     * @param list<string>           $path
     * @param list<YamlSequenceItem> $sequence
     */
    private function fallbackScalar(int $start, string $fragment, array $path, array $sequence, string $scope): ?YamlScalar
    {
        if ('' === $fragment || str_starts_with($fragment, '#')) {
            return null;
        }
        $tag = null;
        $tagStart = null;
        $tagEnd = null;
        if (preg_match('/^(?<tag>!(?:<[^>]*>|[^\s]+))(?<space>\s*)/', $fragment, $match)) {
            $tag = $match['tag'];
            $tagStart = $start;
            $tagEnd = $start + \strlen($tag);
            $shift = \strlen($match[0]);
            $fragment = substr($fragment, $shift);
            $start += $shift;
        }
        if ('' === $fragment || str_starts_with($fragment, '#')) {
            return null;
        }

        $style = match ($fragment[0]) {
            "'" => YamlScalarStyle::SingleQuoted,
            '"' => YamlScalarStyle::DoubleQuoted,
            '|' => YamlScalarStyle::BlockLiteral,
            '>' => YamlScalarStyle::BlockFolded,
            default => YamlScalarStyle::Plain,
        };
        $raw = $this->fallbackRawScalar($fragment, $style);
        if ('' === $raw || (YamlScalarStyle::Plain === $style && \in_array($raw[0], ['[', '{'], true))) {
            return null;
        }
        $end = $start + \strlen($raw);
        [$contentStart, $contentEnd] = $this->decoder->contentOffsets($raw, $start, $end, $style);

        return new YamlScalar(
            $this->decoder->decode($raw, $style),
            $raw,
            $start,
            $end,
            $contentStart,
            $contentEnd,
            $style,
            $path,
            $sequence,
            'base' === $scope ? null : substr($scope, \strlen('when@')),
            $tag,
            $tagStart,
            $tagEnd,
        );
    }

    private function fallbackRawScalar(string $fragment, YamlScalarStyle $style): string
    {
        if (\in_array($style, [YamlScalarStyle::BlockLiteral, YamlScalarStyle::BlockFolded], true)) {
            return preg_match('/^[|>](?:[+-][1-9]?|[1-9][+-]?)?/', $fragment, $match) ? $match[0] : $fragment[0];
        }
        if (\in_array($style, [YamlScalarStyle::SingleQuoted, YamlScalarStyle::DoubleQuoted], true)) {
            $quote = $fragment[0];
            $escaped = false;
            for ($index = 1, $length = \strlen($fragment); $index < $length; ++$index) {
                $character = $fragment[$index];
                if ('"' === $quote && $escaped) {
                    $escaped = false;
                    continue;
                }
                if ('"' === $quote && '\\' === $character) {
                    $escaped = true;
                    continue;
                }
                if ($quote !== $character) {
                    continue;
                }
                if ("'" === $quote && "'" === ($fragment[$index + 1] ?? null)) {
                    ++$index;
                    continue;
                }

                return substr($fragment, 0, $index + 1);
            }

            return $fragment;
        }

        $comment = $this->commentOffset($fragment);

        return rtrim(null === $comment ? $fragment : substr($fragment, 0, $comment));
    }

    private function commentOffset(string $value): ?int
    {
        for ($index = 0, $length = \strlen($value); $index < $length; ++$index) {
            if ('#' === $value[$index] && (0 === $index || ctype_space($value[$index - 1]))) {
                return $index;
            }
        }

        return null;
    }

    /** @param array{start: int, end: int, indent: int, path: list<string>, sequence: list<YamlSequenceItem>, scope: string, tag: string|null, tagStart: int|null, tagEnd: int|null} $block */
    private function recoveredBlockScalar(string $source, array $block): YamlScalar
    {
        $raw = substr($source, $block['start'], $block['end'] - $block['start']);
        $style = str_starts_with($raw, '|') ? YamlScalarStyle::BlockLiteral : YamlScalarStyle::BlockFolded;
        [$contentStart, $contentEnd] = $this->decoder->contentOffsets($raw, $block['start'], $block['end'], $style, $block['indent']);

        return new YamlScalar(
            $this->decoder->decode($raw, $style, $block['indent']),
            $raw,
            $block['start'],
            $block['end'],
            $contentStart,
            $contentEnd,
            $style,
            $block['path'],
            $block['sequence'],
            'base' === $block['scope'] ? null : substr($block['scope'], \strlen('when@')),
            $block['tag'],
            $block['tagStart'],
            $block['tagEnd'],
        );
    }
}
