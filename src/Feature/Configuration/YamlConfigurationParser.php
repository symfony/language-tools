<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlMapping;

final class YamlConfigurationParser
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly YamlDocumentParser $parser,
        private readonly Parser $semanticParser = new Parser(),
    ) {
    }

    /** @return list<ConfigurationOccurrence> */
    public function parse(string $text, ?ConfigurationIndex $index = null, bool $resolveMerges = false): array
    {
        $occurrences = [];
        $merges = [];
        $known = [];
        $mappings = $this->parser->parse($text);
        $siblings = $this->siblingKeys($mappings);
        foreach ($mappings as $mapping) {
            if ([] !== $mapping->path && '<<' === $mapping->path[\count($mapping->path) - 1]) {
                $merges[] = $mapping;
                continue;
            }
            $literalDepths = $this->literalDepths($mapping, $siblings);
            $path = null === $index
                ? $this->normalizePath($mapping->path, $literalDepths)
                : $index->normalizePath($mapping->path, $mapping->sequenceDepths, $literalDepths);
            $occurrence = $this->occurrence($text, $mapping, $path, $literalDepths);
            $occurrences[] = $occurrence;
            $known[$this->identity($occurrence->scope, $occurrence->path)] = true;
        }
        if ($resolveMerges && null !== $index && [] !== $merges) {
            array_push($occurrences, ...$this->resolvedMergeOccurrences($text, $index, $merges, $known));
        }

        return $occurrences;
    }

    /**
     * @param list<string> $path
     * @param list<int>    $literalDepths
     */
    private function occurrence(string $text, YamlMapping $mapping, array $path, array $literalDepths): ConfigurationOccurrence
    {
        return new ConfigurationOccurrence(
            $path,
            $mapping->value,
            new Range($this->converter->toPosition($text, $mapping->keyStartByte), $this->converter->toPosition($text, $mapping->keyEndByte)),
            new Range($this->converter->toPosition($text, $mapping->valueStartByte), $this->converter->toPosition($text, $mapping->valueEndByte)),
            $mapping->sequenceDepths,
            $mapping->scope,
            $literalDepths,
        );
    }

    /**
     * @param list<YamlMapping>   $merges
     * @param array<string, true> $known
     *
     * @return list<ConfigurationOccurrence>
     */
    private function resolvedMergeOccurrences(string $text, ConfigurationIndex $index, array $merges, array $known): array
    {
        try {
            $resolved = $this->semanticParser->parse($text, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException) {
            return [];
        }
        if (!\is_array($resolved)) {
            return [];
        }

        $occurrences = [];
        $processed = [];
        foreach ($merges as $merge) {
            if ($merge->isSequenceItem()) {
                continue;
            }
            $targetPath = \array_slice($merge->path, 0, -1);
            $target = $this->resolvedSubtree($resolved, $merge->scope, $targetPath);
            if (!\is_array($target)) {
                continue;
            }
            $targetIdentity = $this->identity($merge->scope, $targetPath);
            if (isset($processed[$targetIdentity])) {
                continue;
            }
            $processed[$targetIdentity] = true;
            $range = new Range($this->converter->toPosition($text, $merge->keyStartByte), $this->converter->toPosition($text, $merge->keyEndByte));
            $this->appendResolvedOccurrences($index, $target, $targetPath, $merge->sequenceDepths, $merge->scope, $range, $known, $occurrences);
        }

        return $occurrences;
    }

    /**
     * @param array<array-key, mixed>       $resolved
     * @param list<string>                  $path
     * @param list<int>                     $sequenceDepths
     * @param array<string, true>           $known
     * @param list<ConfigurationOccurrence> $occurrences
     */
    private function appendResolvedOccurrences(ConfigurationIndex $index, array $resolved, array $path, array $sequenceDepths, string $scope, Range $range, array &$known, array &$occurrences): void
    {
        foreach ($resolved as $name => $value) {
            $childPath = [...$path, (string) $name];
            $normalizedPath = $index->normalizePath($childPath, $sequenceDepths);
            $identity = $this->identity($scope, $normalizedPath);
            if (!isset($known[$identity])) {
                $known[$identity] = true;
                $occurrences[] = new ConfigurationOccurrence(
                    $normalizedPath,
                    '',
                    $range,
                    $range,
                    $sequenceDepths,
                    $scope,
                );
            }
            if (\is_array($value) && !array_is_list($value) && null !== $index->find($normalizedPath, $sequenceDepths)) {
                $this->appendResolvedOccurrences($index, $value, $childPath, $sequenceDepths, $scope, $range, $known, $occurrences);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $resolved
     * @param list<string>            $path
     */
    private function resolvedSubtree(array $resolved, string $scope, array $path): mixed
    {
        $current = $resolved;
        $path = 'base' === $scope ? $path : [$scope, ...$path];
        foreach ($path as $part) {
            if (!\is_array($current) || !\array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /** @param list<string> $path */
    private function identity(string $scope, array $path): string
    {
        return $scope."\x1f".implode("\x1f", $path);
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
            $path = $mapping->path;
            if ([] === $path) {
                continue;
            }
            $group = $mapping->scope."\x1f".implode("\x1f", \array_slice($path, 0, -1));
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
        $path = $mapping->path;
        foreach ($path as $depth => $name) {
            if (\in_array($depth, $mapping->sequenceDepths, true)
                || !str_contains($name, '-')
                || str_contains($name, '_')
            ) {
                continue;
            }
            $group = $mapping->scope."\x1f".implode("\x1f", \array_slice($path, 0, $depth));
            if (isset($siblings[$group][str_replace('-', '_', $name)])) {
                $literalDepths[] = $depth;
            }
        }

        return $literalDepths;
    }
}
