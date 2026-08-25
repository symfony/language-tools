<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlMapping;

final class YamlConfigurationParser
{
    public function __construct(private readonly PositionConverter $converter, private readonly YamlDocumentParser $parser)
    {
    }

    /** @return list<ConfigurationOccurrence> */
    public function parse(string $text, ?ConfigurationIndex $index = null): array
    {
        $occurrences = [];
        $mappings = $this->parser->parse($text);
        $siblings = $this->siblingKeys($mappings);
        foreach ($mappings as $mapping) {
            $literalDepths = $this->literalDepths($mapping, $siblings);
            $path = null === $index
                ? $this->normalizePath($mapping->path(), $literalDepths)
                : $index->normalizePath($mapping->path(), $mapping->sequenceDepths(), $literalDepths);
            $occurrences[] = new ConfigurationOccurrence(
                $path,
                $mapping->value(),
                new Range($this->converter->toPosition($text, $mapping->keyStartByte()), $this->converter->toPosition($text, $mapping->keyEndByte())),
                new Range($this->converter->toPosition($text, $mapping->valueStartByte()), $this->converter->toPosition($text, $mapping->valueEndByte())),
                $mapping->sequenceDepths(),
                $mapping->scope(),
                $literalDepths,
            );
        }

        return $occurrences;
    }

    /** @return list<string> */
    public function parentPath(string $text, int $offset): array
    {
        return $this->normalizePath($this->parser->parentPath($text, $offset));
    }

    /**
     * @param list<string> $path
     * @param list<int>    $literalDepths
     *
     * @return list<string>
     */
    private function normalizePath(array $path, array $literalDepths = []): array
    {
        foreach ($path as $depth => &$part) {
            if (!\in_array($depth, $literalDepths, true)) {
                $part = ConfigurationNode::normalizeKey($part);
            }
        }
        unset($part);

        return $path;
    }

    /**
     * @param list<YamlMapping> $mappings
     *
     * @return array<string, array<string, true>>
     */
    private function siblingKeys(array $mappings): array
    {
        $siblings = [];
        foreach ($mappings as $mapping) {
            $path = $mapping->path();
            if ([] === $path) {
                continue;
            }
            $group = $mapping->scope()."\x1f".implode("\x1f", \array_slice($path, 0, -1));
            $siblings[$group][$path[\count($path) - 1]] = true;
        }

        return $siblings;
    }

    /**
     * Symfony's ArrayNode::preNormalize keeps a dash key literal when its
     * underscore twin exists in the same array.
     *
     * @param array<string, array<string, true>> $siblings
     *
     * @return list<int>
     */
    private function literalDepths(YamlMapping $mapping, array $siblings): array
    {
        $literalDepths = [];
        $path = $mapping->path();
        foreach ($path as $depth => $name) {
            if (\in_array($depth, $mapping->sequenceDepths(), true)
                || !str_contains($name, '-')
                || str_contains($name, '_')
            ) {
                continue;
            }
            $group = $mapping->scope()."\x1f".implode("\x1f", \array_slice($path, 0, $depth));
            if (isset($siblings[$group][str_replace('-', '_', $name)])) {
                $literalDepths[] = $depth;
            }
        }

        return $literalDepths;
    }
}
