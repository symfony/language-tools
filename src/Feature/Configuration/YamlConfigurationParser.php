<?php

namespace Symfony\Lsp\Feature\Configuration;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlMapping;
use Symfony\Lsp\Parser\Yaml\YamlScalar;

final class YamlConfigurationParser
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly YamlDocumentParser $parser,
        private readonly Parser $semanticParser = new Parser(),
    ) {
    }

    /** @return list<ConfigurationOccurrence> */
    public function parse(string $text, ?ConfigurationIndex $index = null, bool $resolveAliasesAndMerges = false): array
    {
        $document = $resolveAliasesAndMerges && null !== $index ? $this->parser->parseDocument($text) : null;
        $mappings = null === $document ? $this->parser->parse($text) : $document->mappings;
        $merges = [];
        $aliases = [];
        $anchors = [];
        foreach ($mappings as $mapping) {
            if ([] !== $mapping->path && '<<' === $mapping->path[\count($mapping->path) - 1]) {
                $merges[] = $mapping;
            } elseif ($this->isAlias($mapping->value)) {
                $aliases[] = $mapping;
            } elseif ($this->isAnchor($mapping->value)) {
                $anchors[] = $mapping;
            }
        }

        $resolved = null;
        if (null !== $document && ([] !== $merges || [] !== $aliases || [] !== $anchors)) {
            $resolved = $this->resolvedDocument($text, $document->scalars);
        }

        $occurrences = [];
        $known = [];
        $siblings = $this->siblingKeys($mappings);
        foreach ($mappings as $mapping) {
            if ([] !== $mapping->path && '<<' === $mapping->path[\count($mapping->path) - 1]) {
                continue;
            }
            $literalDepths = $this->literalDepths($mapping, $siblings);
            $path = null === $index
                ? $this->normalizePath($mapping->path, $literalDepths)
                : $index->normalizePath($mapping->path, $mapping->sequenceDepths, $literalDepths);
            $value = $mapping->value;
            $hasResolvedValue = false;
            $resolvedValue = null;
            if ($resolveAliasesAndMerges && null !== $index && ($this->isAlias($value) || $this->isAnchor($value))) {
                $value = '';
                if (null !== $resolved) {
                    [$hasResolvedValue, $resolvedValue] = $this->resolvedSubtree($resolved, $mapping->scope, $mapping->path);
                }
            }
            $occurrence = $this->occurrence($text, $mapping, $path, $literalDepths, $value, $hasResolvedValue, $resolvedValue);
            $occurrences[] = $occurrence;
            $known[$this->identity($occurrence->scope, $occurrence->path)] = true;
        }
        if (null !== $index && null !== $resolved) {
            array_push($occurrences, ...$this->resolvedMergeOccurrences($text, $index, $resolved, $merges, $known));
            array_push($occurrences, ...$this->resolvedAliasOccurrences($text, $index, $resolved, $aliases, $known));
        }

        return $occurrences;
    }

    /**
     * @param list<string> $path
     * @param list<int>    $literalDepths
     */
    private function occurrence(string $text, YamlMapping $mapping, array $path, array $literalDepths, string $value, bool $hasResolvedValue, mixed $resolvedValue): ConfigurationOccurrence
    {
        return new ConfigurationOccurrence(
            $path,
            $value,
            new Range($this->converter->toPosition($text, $mapping->keyStartByte), $this->converter->toPosition($text, $mapping->keyEndByte)),
            new Range($this->converter->toPosition($text, $mapping->valueStartByte), $this->converter->toPosition($text, $mapping->valueEndByte)),
            $mapping->sequenceDepths,
            $mapping->scope,
            $literalDepths,
            $hasResolvedValue,
            $resolvedValue,
        );
    }

    /**
     * @param list<YamlScalar> $scalars
     *
     * @return array<array-key, mixed>|null
     */
    private function resolvedDocument(string $text, array $scalars): ?array
    {
        $replacements = [];
        foreach ($scalars as $scalar) {
            $replacement = match ($scalar->tag) {
                '!php/enum' => '!symfony-lsp/php-enum',
                '!php/const' => '!symfony-lsp/php-const',
                '!php/object' => '!symfony-lsp/php-object',
                default => null,
            };
            if (null === $replacement) {
                continue;
            }
            if (null === $scalar->tagStartByte || null === $scalar->tagEndByte) {
                return null;
            }
            $replacements[$scalar->tagStartByte] = [$scalar->tagEndByte, $replacement];
        }
        krsort($replacements, \SORT_NUMERIC);
        foreach ($replacements as $start => [$end, $replacement]) {
            $text = substr_replace($text, $replacement, $start, $end - $start);
        }

        try {
            $resolved = $this->semanticParser->parse($text, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException) {
            return null;
        }

        return \is_array($resolved) ? $resolved : null;
    }

    /**
     * @param array<array-key, mixed> $resolved
     * @param list<YamlMapping>       $merges
     * @param array<string, true>     $known
     *
     * @return list<ConfigurationOccurrence>
     */
    private function resolvedMergeOccurrences(string $text, ConfigurationIndex $index, array $resolved, array $merges, array &$known): array
    {
        $occurrences = [];
        $processed = [];
        foreach ($merges as $merge) {
            if ($merge->isSequenceItem()) {
                continue;
            }
            $targetPath = \array_slice($merge->path, 0, -1);
            [$found, $target] = $this->resolvedSubtree($resolved, $merge->scope, $targetPath);
            if (!$found || !\is_array($target)) {
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
     * @param array<array-key, mixed> $resolved
     * @param list<YamlMapping>       $aliases
     * @param array<string, true>     $known
     *
     * @return list<ConfigurationOccurrence>
     */
    private function resolvedAliasOccurrences(string $text, ConfigurationIndex $index, array $resolved, array $aliases, array &$known): array
    {
        $occurrences = [];
        $processed = [];
        foreach ($aliases as $alias) {
            if ($alias->isSequenceItem()) {
                continue;
            }
            [$found, $target] = $this->resolvedSubtree($resolved, $alias->scope, $alias->path);
            if (!$found || !\is_array($target) || array_is_list($target)) {
                continue;
            }
            $targetIdentity = $this->identity($alias->scope, $alias->path);
            if (isset($processed[$targetIdentity])) {
                continue;
            }
            $processed[$targetIdentity] = true;
            $range = new Range($this->converter->toPosition($text, $alias->valueStartByte), $this->converter->toPosition($text, $alias->valueEndByte));
            $this->appendResolvedOccurrences($index, $target, $alias->path, $alias->sequenceDepths, $alias->scope, $range, $known, $occurrences);
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
            if (isset($known[$identity])) {
                continue;
            }
            $known[$identity] = true;
            $occurrences[] = new ConfigurationOccurrence(
                $normalizedPath,
                '',
                $range,
                $range,
                $sequenceDepths,
                $scope,
                hasResolvedValue: true,
                resolvedValue: $value,
            );
            if (\is_array($value) && !array_is_list($value) && null !== $index->find($normalizedPath, $sequenceDepths)) {
                $this->appendResolvedOccurrences($index, $value, $childPath, $sequenceDepths, $scope, $range, $known, $occurrences);
            }
        }
    }

    private function isAlias(string $value): bool
    {
        return str_starts_with(ltrim($value), '*');
    }

    private function isAnchor(string $value): bool
    {
        return str_starts_with(ltrim($value), '&');
    }

    /**
     * @param array<array-key, mixed> $resolved
     * @param list<string>            $path
     *
     * @return array{bool, mixed}
     */
    private function resolvedSubtree(array $resolved, string $scope, array $path): array
    {
        $current = $resolved;
        $path = 'base' === $scope ? $path : [$scope, ...$path];
        foreach ($path as $part) {
            if (!\is_array($current) || !\array_key_exists($part, $current)) {
                return [false, null];
            }
            $current = $current[$part];
        }

        return [true, $current];
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
