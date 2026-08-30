<?php

namespace Symfony\Lsp\Parser\Yaml;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterTree;

final class YamlDocumentParser
{
    public function __construct(
        private readonly TreeSitterParserInterface $parser,
        private readonly YamlScalarDecoder $scalarDecoder = new YamlScalarDecoder(),
        private readonly YamlRecoveryParser $recoveryParser = new YamlRecoveryParser(),
    ) {
    }

    /** @return list<YamlMapping> */
    public function parse(string $source): array
    {
        return $this->parseSource($source, false)->mappings;
    }

    public function parseDocument(string $source): YamlDocument
    {
        return $this->parseSource($source, true);
    }

    private function parseSource(string $source, bool $collectScalars): YamlDocument
    {
        $mappings = [];
        $scalars = [];
        $tree = $this->parser->parse('yaml', $source);
        $this->visit($tree, $tree->root(), $source, [], [], 'base', $collectScalars, $mappings, $scalars);
        if ($tree->hasError) {
            $recovered = $this->recoveryParser->parse($source);
            $mappings = $this->mergeMappings($mappings, $recovered->mappings);
            if ($collectScalars) {
                $scalars = $this->mergeScalars($scalars, $recovered->scalars);
            }
        }

        return new YamlDocument($mappings, $scalars);
    }

    /** @return list<string> */
    public function parentPath(string $source, int $offset): array
    {
        $mappings = $this->parse(substr($source, 0, $offset));
        if ([] === $mappings) {
            return [];
        }
        $mapping = $mappings[array_key_last($mappings)];

        return '' === $mapping->value ? $mapping->path : \array_slice($mapping->path, 0, -1);
    }

    /**
     * @param list<string>           $path
     * @param list<YamlSequenceItem> $sequence
     * @param list<YamlMapping>      $mappings
     * @param list<YamlScalar>       $scalars
     */
    private function visit(TreeSitterTree $tree, TreeSitterNode $node, string $source, array $path, array $sequence, string $scope, bool $collectScalars, array &$mappings, array &$scalars): void
    {
        if (\in_array($node->type, ['block_mapping_pair', 'flow_pair'], true)) {
            $this->visitPair($tree, $node, $source, $path, $sequence, $scope, $collectScalars, $mappings, $scalars);

            return;
        }
        if (\in_array($node->type, ['block_sequence', 'flow_sequence'], true)) {
            $index = 0;
            foreach ($tree->children($node) as $child) {
                if ('block_sequence' === $node->type && 'block_sequence_item' !== $child->type) {
                    continue;
                }
                $item = new YamlSequenceItem(\count($path), $index++);
                $this->visit($tree, $child, $source, $path, [...$sequence, $item], $scope, $collectScalars, $mappings, $scalars);
            }

            return;
        }

        $scalarNode = $this->directScalarNode($tree, $node);
        if (null !== $scalarNode) {
            if ($collectScalars) {
                $scalars[] = $this->treeScalar($tree, $node, $scalarNode, $source, $path, $sequence, $scope);
            }

            return;
        }

        foreach ($tree->children($node) as $child) {
            $this->visit($tree, $child, $source, $path, $sequence, $scope, $collectScalars, $mappings, $scalars);
        }
    }

    /**
     * @param list<string>           $path
     * @param list<YamlSequenceItem> $sequence
     * @param list<YamlMapping>      $mappings
     * @param list<YamlScalar>       $scalars
     */
    private function visitPair(TreeSitterTree $tree, TreeSitterNode $node, string $source, array $path, array $sequence, string $scope, bool $collectScalars, array &$mappings, array &$scalars): void
    {
        $keyNode = $tree->childByField($node, 'key');
        if (null === $keyNode) {
            return;
        }
        [$key, $keyStart, $keyEnd] = $this->key($tree, $keyNode, $source);
        if ('' === $key) {
            return;
        }

        $valueNode = $tree->childByField($node, 'value');
        $environmentSection = str_starts_with($key, 'when@');
        $mappingPath = $environmentSection ? $path : [...$path, $key];
        $mappingScope = $environmentSection ? $key : $scope;
        if (!$environmentSection) {
            [$value, $valueStart, $valueEnd] = $this->mappingValue($tree, $valueNode, $source, $node->endByte);
            $mappings[] = new YamlMapping(
                $mappingPath,
                $value,
                $keyStart,
                $keyEnd,
                $valueStart,
                $valueEnd,
                array_values(array_unique(array_map(static fn (YamlSequenceItem $item): int => $item->pathDepth, $sequence))),
                $mappingScope,
            );
        }

        if (null !== $valueNode) {
            $this->visit($tree, $valueNode, $source, $mappingPath, $sequence, $mappingScope, $collectScalars, $mappings, $scalars);
        }
    }

    /** @return array{string, int, int} */
    private function key(TreeSitterTree $tree, TreeSitterNode $node, string $source): array
    {
        $scalar = $this->directScalarNode($tree, $node);
        if (null === $scalar) {
            return ['', $node->startByte, $node->endByte];
        }
        $raw = $tree->text($scalar, $source);
        $style = $this->scalarDecoder->style($scalar->type, $raw);
        [$start, $end] = $this->scalarDecoder->contentOffsets($raw, $scalar->startByte, $scalar->endByte, $style);

        return [$this->scalarDecoder->decode($raw, $style), $start, $end];
    }

    /** @return array{string, int, int} */
    private function mappingValue(TreeSitterTree $tree, ?TreeSitterNode $node, string $source, int $fallbackOffset): array
    {
        if (null === $node || $this->containsBlockCollection($tree, $node)) {
            while (isset($source[$fallbackOffset]) && \in_array($source[$fallbackOffset], [' ', "\t"], true)) {
                ++$fallbackOffset;
            }

            return ['', $fallbackOffset, $fallbackOffset];
        }

        return [trim($tree->text($node, $source)), $node->startByte, $node->endByte];
    }

    private function directScalarNode(TreeSitterTree $tree, TreeSitterNode $node): ?TreeSitterNode
    {
        if (\in_array($node->type, ['plain_scalar', 'single_quote_scalar', 'double_quote_scalar', 'block_scalar'], true)) {
            return $node;
        }
        foreach ($tree->children($node) as $child) {
            if (\in_array($child->type, ['plain_scalar', 'single_quote_scalar', 'double_quote_scalar', 'block_scalar'], true)) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @param list<string>           $path
     * @param list<YamlSequenceItem> $sequence
     */
    private function treeScalar(TreeSitterTree $tree, TreeSitterNode $container, TreeSitterNode $node, string $source, array $path, array $sequence, string $scope): YamlScalar
    {
        $raw = $tree->text($node, $source);
        $style = $this->scalarDecoder->style($node->type, $raw);
        $baseIndent = $this->lineIndent($source, $node->startByte);
        [$contentStart, $contentEnd] = $this->scalarDecoder->contentOffsets($raw, $node->startByte, $node->endByte, $style, $baseIndent);
        $tag = null;
        foreach ($tree->children($container) as $child) {
            if ('tag' === $child->type) {
                $tag = $tree->text($child, $source);
                break;
            }
        }

        return new YamlScalar(
            $this->scalarDecoder->decode($raw, $style, $baseIndent),
            $raw,
            $node->startByte,
            $node->endByte,
            $contentStart,
            $contentEnd,
            $style,
            $path,
            $sequence,
            'base' === $scope ? null : substr($scope, \strlen('when@')),
            $tag,
        );
    }

    private function lineIndent(string $source, int $offset): int
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = false === $lineStart ? 0 : $lineStart + 1;

        return strspn($source, " \t", $lineStart, $offset - $lineStart);
    }

    /**
     * @param list<YamlMapping> $parsed
     * @param list<YamlMapping> $recovered
     *
     * @return list<YamlMapping>
     */
    private function mergeMappings(array $parsed, array $recovered): array
    {
        $indexed = [];
        foreach ($parsed as $mapping) {
            $indexed[$mapping->keyStartByte] = true;
        }
        foreach ($recovered as $mapping) {
            if (!isset($indexed[$mapping->keyStartByte])) {
                $parsed[] = $mapping;
            }
        }
        usort($parsed, static fn (YamlMapping $left, YamlMapping $right): int => $left->keyStartByte <=> $right->keyStartByte);

        return $parsed;
    }

    /**
     * @param list<YamlScalar> $parsed
     * @param list<YamlScalar> $recovered
     *
     * @return list<YamlScalar>
     */
    private function mergeScalars(array $parsed, array $recovered): array
    {
        $merged = [];
        foreach ($recovered as $scalar) {
            $merged[$scalar->startByte."\0".$scalar->endByte] = $scalar;
        }
        foreach ($parsed as $scalar) {
            $key = $scalar->startByte."\0".$scalar->endByte;
            $merged[$key] ??= $scalar;
        }
        $scalars = array_values($merged);
        usort($scalars, static fn (YamlScalar $left, YamlScalar $right): int => $left->startByte <=> $right->startByte);

        return $scalars;
    }

    private function containsBlockCollection(TreeSitterTree $tree, TreeSitterNode $node): bool
    {
        if (\in_array($node->type, ['block_mapping', 'block_sequence'], true)) {
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
