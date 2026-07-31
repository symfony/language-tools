<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class YamlConfigurationParser
{
    public function __construct(private readonly PositionConverter $converter)
    {
    }

    /** @return list<ConfigurationOccurrence> */
    public function parse(string $text): array
    {
        $occurrences = [];
        /** @var array<int, array{path: list<string>, sequence: bool, scope: string}> $stack */
        $stack = [];
        preg_match_all('/^.*(?:\R|$)/m', $text, $lines, \PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as [$line, $lineOffset]) {
            $line = rtrim($line, "\r\n");
            if (!preg_match('/^(\s*)(?:(-)\s+)?([A-Za-z_][A-Za-z0-9_.@-]*)\s*:(.*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $indent = \strlen($match[1][0]);
            $key = $match[3][0];
            $value = $this->value($match[4][0]);
            foreach (array_keys($stack) as $level) {
                if ($level >= $indent) {
                    unset($stack[$level]);
                }
            }
            ksort($stack);
            $parent = [];
            $insideSequence = false;
            $scope = 'base';
            foreach ($stack as $entry) {
                $parent = $entry['path'];
                $insideSequence = $entry['sequence'];
                $scope = $entry['scope'];
            }
            $environmentSection = str_starts_with($key, 'when@');
            if ($environmentSection) {
                $scope = $key;
            }
            $sequenceItem = $insideSequence || '-' === $match[2][0];
            $schemaKey = str_replace('-', '_', $key);
            $path = $environmentSection ? $parent : [...$parent, $schemaKey];
            if ($environmentSection || '' === $value) {
                $stack[$indent] = ['path' => $path, 'sequence' => $sequenceItem, 'scope' => $scope];
            } elseif ('-' === $match[2][0]) {
                $stack[$indent] = ['path' => $parent, 'sequence' => true, 'scope' => $scope];
            }
            if (str_starts_with($key, 'when@')) {
                continue;
            }
            $keyOffset = $lineOffset + $match[3][1];
            $valueOffset = $lineOffset + $match[4][1] + (\strlen($match[4][0]) - \strlen(ltrim($match[4][0])));
            $occurrences[] = new ConfigurationOccurrence(
                $path,
                $value,
                $this->range($text, $keyOffset, \strlen($key)),
                $this->range($text, $valueOffset, \strlen($value)),
                $sequenceItem,
                $scope,
            );
        }

        return $occurrences;
    }

    private function value(string $value): string
    {
        $quote = null;
        for ($index = 0, $length = \strlen($value); $index < $length; ++$index) {
            $character = $value[$index];
            if (null === $quote && \in_array($character, ["'", '"'], true)) {
                $quote = $character;
                continue;
            }
            if ($character === $quote && ('"' !== $quote || 0 === $index || '\\' !== $value[$index - 1])) {
                $quote = null;
                continue;
            }
            if (null === $quote && '#' === $character && (0 === $index || ctype_space($value[$index - 1]))) {
                return trim(substr($value, 0, $index));
            }
        }

        return trim($value);
    }

    private function range(string $text, int $offset, int $length): Range
    {
        return new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + $length));
    }
}
