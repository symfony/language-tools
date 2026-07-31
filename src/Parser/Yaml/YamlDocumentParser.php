<?php

namespace Symfony\Lsp\Parser\Yaml;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterTree;

final class YamlDocumentParser
{
    public function __construct(private readonly TreeSitterParserInterface $parser)
    {
    }

    /** @return list<YamlMapping> */
    public function parse(string $source): array
    {
        $mappings = [];
        $tree = $this->parser->parse('yaml', $source);
        $this->visit($tree, $tree->root(), $source, [], false, 'base', $mappings);
        if ($tree->hasError()) {
            $indexedOffsets = [];
            foreach ($mappings as $mapping) {
                $indexedOffsets[$mapping->keyStartByte()] = true;
            }
            foreach ($this->fallbackMappings($source) as $mapping) {
                if (!isset($indexedOffsets[$mapping->keyStartByte()])) {
                    $mappings[] = $mapping;
                }
            }
            usort($mappings, static fn (YamlMapping $left, YamlMapping $right): int => $left->keyStartByte() <=> $right->keyStartByte());
        }

        return $mappings;
    }

    /**
     * @param list<string>      $path
     * @param list<YamlMapping> $mappings
     */
    private function visit(TreeSitterTree $tree, TreeSitterNode $node, string $source, array $path, bool $sequenceItem, string $scope, array &$mappings): void
    {
        if ('block_sequence_item' === $node->type()) {
            $sequenceItem = true;
        }

        if (\in_array($node->type(), ['block_mapping_pair', 'flow_pair'], true)) {
            $keyNode = $tree->childByField($node, 'key');
            if (null === $keyNode) {
                return;
            }
            [$key, $keyStart, $keyEnd] = $this->scalar($tree, $keyNode, $source);
            if ('' === $key) {
                return;
            }

            $valueNode = $tree->childByField($node, 'value');
            $environmentSection = str_starts_with($key, 'when@');
            $mappingPath = $environmentSection ? $path : [...$path, str_replace('-', '_', $key)];
            $mappingScope = $environmentSection ? $key : $scope;
            if (!$environmentSection) {
                [$value, $valueStart, $valueEnd] = $this->value($tree, $valueNode, $source, $node->endByte());
                $mappings[] = new YamlMapping($mappingPath, $value, $keyStart, $keyEnd, $valueStart, $valueEnd, $sequenceItem, $mappingScope);
            }

            if (null !== $valueNode) {
                $this->visit($tree, $valueNode, $source, $mappingPath, $sequenceItem, $mappingScope, $mappings);
            }

            return;
        }

        foreach ($tree->children($node) as $child) {
            $this->visit($tree, $child, $source, $path, $sequenceItem, $scope, $mappings);
        }
    }

    /** @return array{string, int, int} */
    private function scalar(TreeSitterTree $tree, TreeSitterNode $node, string $source): array
    {
        $start = $node->startByte();
        $end = $node->endByte();
        $value = $tree->text($node, $source);
        if (\strlen($value) >= 2 && (("'" === $value[0] && str_ends_with($value, "'")) || ('"' === $value[0] && str_ends_with($value, '"')))) {
            ++$start;
            --$end;
            $value = substr($value, 1, -1);
        }

        return [$value, $start, $end];
    }

    /** @return array{string, int, int} */
    private function value(TreeSitterTree $tree, ?TreeSitterNode $node, string $source, int $fallbackOffset): array
    {
        if (null === $node || $this->containsBlockCollection($tree, $node)) {
            while (isset($source[$fallbackOffset]) && \in_array($source[$fallbackOffset], [' ', "\t"], true)) {
                ++$fallbackOffset;
            }

            return ['', $fallbackOffset, $fallbackOffset];
        }

        return [trim($tree->text($node, $source)), $node->startByte(), $node->endByte()];
    }

    /** @return list<YamlMapping> */
    private function fallbackMappings(string $source): array
    {
        $mappings = [];
        /** @var array<int, array{path: list<string>, sequence: bool, scope: string}> $stack */
        $stack = [];
        preg_match_all('/^.*(?:\R|$)/m', $source, $lines, \PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as [$line, $lineOffset]) {
            $line = rtrim($line, "\r\n");
            $parsed = $this->fallbackLine($line);
            if (null === $parsed) {
                continue;
            }
            foreach (array_keys($stack) as $level) {
                if ($level >= $parsed['indent']) {
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
            $environmentSection = str_starts_with($parsed['key'], 'when@');
            if ($environmentSection) {
                $scope = $parsed['key'];
            }
            $sequenceItem = $insideSequence || $parsed['sequence'];
            $path = $environmentSection ? $parent : [...$parent, str_replace('-', '_', $parsed['key'])];
            if ($environmentSection || '' === $parsed['value']) {
                $stack[$parsed['indent']] = ['path' => $path, 'sequence' => $sequenceItem, 'scope' => $scope];
            } elseif ($parsed['sequence']) {
                $stack[$parsed['indent']] = ['path' => $parent, 'sequence' => true, 'scope' => $scope];
            }
            if ($environmentSection) {
                continue;
            }
            $keyStart = $lineOffset + $parsed['keyOffset'];
            $valueStart = $lineOffset + $parsed['valueOffset'];
            $mappings[] = new YamlMapping(
                $path,
                $parsed['value'],
                $keyStart,
                $keyStart + \strlen($parsed['key']),
                $valueStart,
                $valueStart + \strlen($parsed['value']),
                $sequenceItem,
                $scope,
            );
        }

        return $mappings;
    }

    /** @return array{indent: int, key: string, keyOffset: int, value: string, valueOffset: int, sequence: bool}|null */
    private function fallbackLine(string $line): ?array
    {
        if ('' === trim($line) || str_starts_with(ltrim($line), '#') || !preg_match('/^(?<indent>\s*)(?:(?<sequence>-)\s+)?(?<quote>[\'"]?)(?<key>[^:\'"]+)\k<quote>\s*:(?<rest>.*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $key = trim($match['key'][0]);
        $keyOffset = $match['key'][1] + \strlen($match['key'][0]) - \strlen(ltrim($match['key'][0]));
        $rest = $match['rest'][0];
        $value = $this->fallbackValue($rest);
        $valueOffset = $match['rest'][1] + \strlen($rest) - \strlen(ltrim($rest));

        return [
            'indent' => \strlen($match['indent'][0]),
            'key' => $key,
            'keyOffset' => $keyOffset,
            'value' => $value,
            'valueOffset' => $valueOffset,
            'sequence' => '-' === $match['sequence'][0],
        ];
    }

    private function fallbackValue(string $value): string
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

    private function containsBlockCollection(TreeSitterTree $tree, TreeSitterNode $node): bool
    {
        if (\in_array($node->type(), ['block_mapping', 'block_sequence'], true)) {
            return true;
        }
        foreach ($tree->children($node) as $child) {
            if ($this->containsBlockCollection($tree, $child)) {
                return true;
            }
        }

        return false;
    }
}
