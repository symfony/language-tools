<?php

namespace Symfony\Lsp\Parser\Yaml;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterParserInterface;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterTree;

final class YamlDocumentParser
{
    private ?string $source = null;
    private bool $collectedScalars = false;
    private ?YamlDocument $document = null;

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
        if ($source === $this->source && null !== $this->document && ($this->collectedScalars || !$collectScalars)) {
            return $this->document;
        }
        $mappings = [];
        $scalars = [];
        $tree = $this->parser->parse('yaml', $source);
        $this->visit($tree, $tree->root(), $source, [], [], 'base', $collectScalars, $mappings, $scalars);
        if ($tree->hasError) {
            $recovered = $this->recoveryParser->parse($source);
            $mappings = $this->mergeMappings($mappings, $recovered->mappings);
            if ($collectScalars) {
                $scalars = $this->mergeScalars($source, $scalars, $recovered->scalars);
            }
        }

        $this->source = $source;
        $this->collectedScalars = $collectScalars;

        return $this->document = new YamlDocument($mappings, $scalars);
    }

    /** @return list<string> */
    public function parentPath(string $source, int $offset): array
    {
        $mapping = null;
        foreach ($this->parse($source) as $candidate) {
            if ($candidate->keyEndByte < $offset) {
                $mapping = $candidate;
            }
        }
        if (null === $mapping) {
            return [];
        }

        return '' === $mapping->value || $mapping->valueStartByte >= $offset ? $mapping->path : \array_slice($mapping->path, 0, -1);
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
                $sequence,
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
        $endByte = 'block_scalar' === $node->type ? $this->blockScalarEnd($source, $node->endByte) : $node->endByte;
        $raw = substr($source, $node->startByte, $endByte - $node->startByte);
        $style = $this->scalarDecoder->style($node->type, $raw);
        $baseIndent = $this->lineIndent($source, $node->startByte);
        [$contentStart, $contentEnd] = $this->scalarDecoder->contentOffsets($raw, $node->startByte, $endByte, $style, $baseIndent);
        $tag = null;
        $tagStartByte = null;
        $tagEndByte = null;
        foreach ($tree->children($container) as $child) {
            if ('tag' === $child->type) {
                $tag = $tree->text($child, $source);
                $tagStartByte = $child->startByte;
                $tagEndByte = $child->endByte;
                break;
            }
        }

        return new YamlScalar(
            $this->scalarDecoder->decode($raw, $style, $baseIndent),
            $raw,
            $node->startByte,
            $endByte,
            $contentStart,
            $contentEnd,
            $style,
            $path,
            $sequence,
            'base' === $scope ? null : substr($scope, \strlen('when@')),
            $tag,
            $tagStartByte,
            $tagEndByte,
        );
    }

    private function blockScalarEnd(string $source, int $end): int
    {
        $length = \strlen($source);
        if ($end >= $length || !\in_array($source[$end], ["\r", "\n"], true)) {
            return $end;
        }

        $end += "\r" === $source[$end] && "\n" === ($source[$end + 1] ?? null) ? 2 : 1;
        while ($end < $length) {
            $lineEnd = $end + strcspn($source, "\r\n", $end);
            if ('' !== trim(substr($source, $end, $lineEnd - $end))) {
                break;
            }
            if ($lineEnd >= $length) {
                $end = $lineEnd;
                break;
            }
            $end = $lineEnd + ("\r" === $source[$lineEnd] && "\n" === ($source[$lineEnd + 1] ?? null) ? 2 : 1);
        }

        return $end;
    }

    private function lineIndent(string $source, int $offset): int
    {
        $lineStart = 0 === $offset ? false : strrpos($source, "\n", $offset - \strlen($source) - 1);
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
        foreach ($parsed as $index => $mapping) {
            $indexed[$mapping->keyStartByte] = $index;
        }
        foreach ($recovered as $mapping) {
            $index = $indexed[$mapping->keyStartByte] ?? null;
            if (null === $index) {
                $parsed[] = $mapping;
                continue;
            }
            $parsed[$index] = $this->withRecoveredValue($this->withRecoveredAncestors($parsed[$index], $mapping), $mapping);
        }
        usort($parsed, static fn (YamlMapping $left, YamlMapping $right): int => $left->keyStartByte <=> $right->keyStartByte);

        return $parsed;
    }

    /**
     * An error node reparents pairs under the document root, so the tree keeps
     * the key but loses the ancestors the recovered line indentation knows.
     * A `when@` ancestor adds no path segment, so its loss only shows in the scope.
     */
    private function withRecoveredAncestors(YamlMapping $mapping, YamlMapping $recovered): YamlMapping
    {
        $depth = \count($recovered->path) - \count($mapping->path);
        if ($depth < 0 || \array_slice($recovered->path, $depth) !== $mapping->path) {
            return $mapping;
        }
        $restoresAncestors = 0 < $depth && [] === $mapping->sequence;
        $restoresScope = 'base' === $mapping->scope && 'base' !== $recovered->scope;
        if (!$restoresAncestors && !$restoresScope) {
            return $mapping;
        }

        return new YamlMapping(
            $restoresAncestors ? $recovered->path : $mapping->path,
            $mapping->value,
            $mapping->keyStartByte,
            $mapping->keyEndByte,
            $mapping->valueStartByte,
            $mapping->valueEndByte,
            $restoresAncestors ? $recovered->sequence : $mapping->sequence,
            $recovered->scope,
        );
    }

    /**
     * An error node can split a value from its key, leaving the tree pair
     * empty where the recovered line still reads the value.
     */
    private function withRecoveredValue(YamlMapping $mapping, YamlMapping $recovered): YamlMapping
    {
        if ('' !== $mapping->value || '' === $recovered->value || $mapping->valueStartByte !== $recovered->valueStartByte) {
            return $mapping;
        }

        return new YamlMapping(
            $mapping->path,
            $recovered->value,
            $mapping->keyStartByte,
            $mapping->keyEndByte,
            $recovered->valueStartByte,
            $recovered->valueEndByte,
            $mapping->sequence,
            $mapping->scope,
        );
    }

    /**
     * Recovered scalars only fill regions the tree left uncovered, so a
     * malformed region never yields two facts for the same bytes.
     *
     * @param list<YamlScalar> $parsed
     * @param list<YamlScalar> $recovered
     *
     * @return list<YamlScalar>
     */
    private function mergeScalars(string $source, array $parsed, array $recovered): array
    {
        $recoveredByRange = [];
        foreach ($recovered as $scalar) {
            $recoveredByRange[$scalar->startByte.':'.$scalar->endByte] = $scalar;
        }
        $parsed = array_values(array_filter(
            $parsed,
            fn (YamlScalar $scalar): bool => !$this->isMappingKey($source, $scalar),
        ));
        foreach ($parsed as $index => $scalar) {
            $match = $recoveredByRange[$scalar->startByte.':'.$scalar->endByte] ?? null;
            if (null !== $match) {
                $parsed[$index] = $this->withRecoveredScalarAncestors($scalar, $match);
            }
        }
        $byStartByte = static fn (YamlScalar $left, YamlScalar $right): int => $left->startByte <=> $right->startByte;
        usort($parsed, $byStartByte);
        usort($recovered, $byStartByte);
        $scalars = $parsed;
        $index = 0;
        $count = \count($parsed);
        foreach ($recovered as $scalar) {
            while ($index < $count && $parsed[$index]->endByte <= $scalar->startByte) {
                ++$index;
            }
            if ($index === $count || $parsed[$index]->startByte >= $scalar->endByte) {
                $scalars[] = $scalar;
            }
        }
        usort($scalars, $byStartByte);

        return $scalars;
    }

    /**
     * An error node leaves the keys of the pairs it swallows as bare scalars.
     */
    private function isMappingKey(string $source, YamlScalar $scalar): bool
    {
        return !\in_array($scalar->style, [YamlScalarStyle::BlockLiteral, YamlScalarStyle::BlockFolded], true)
            && 1 === preg_match('/\G[ \t]*:(?:\s|$)/', $source, $match, 0, $scalar->endByte);
    }

    private function withRecoveredScalarAncestors(YamlScalar $scalar, YamlScalar $recovered): YamlScalar
    {
        $depth = \count($recovered->path) - \count($scalar->path);
        if ($depth < 0 || \array_slice($recovered->path, $depth) !== $scalar->path) {
            return $scalar;
        }
        $restoresEnvironment = null === $scalar->environment && null !== $recovered->environment;
        if (0 === $depth && !$restoresEnvironment) {
            return $scalar;
        }

        return new YamlScalar(
            $scalar->value,
            $scalar->raw,
            $scalar->startByte,
            $scalar->endByte,
            $scalar->contentStartByte,
            $scalar->contentEndByte,
            $scalar->style,
            $recovered->path,
            0 < $depth ? $recovered->sequence : $scalar->sequence,
            $recovered->environment,
            $scalar->tag,
            $scalar->tagStartByte,
            $scalar->tagEndByte,
        );
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
