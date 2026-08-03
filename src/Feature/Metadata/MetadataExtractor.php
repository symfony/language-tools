<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;

final class MetadataExtractor
{
    public function __construct(private readonly PositionConverter $converter, private readonly YamlConfigurationParser $yaml)
    {
    }

    public function extract(string $uri, string $languageId, string $text): MetadataSourceFacts
    {
        $symbols = match ($languageId) {
            'php' => $this->phpSymbols($uri, $text),
            'yaml' => $this->yamlSymbols($uri, $text),
            default => [],
        };

        return new MetadataSourceFacts($uri, $this->unique($symbols));
    }

    public function completionContext(string $languageId, string $text, int $offset): ?MetadataCompletionContext
    {
        return match ($languageId) {
            'php' => $this->phpCompletionContext($text, $offset),
            'yaml' => $this->yamlCompletionContext($text, $offset),
            default => null,
        };
    }

    /**
     * @return list<array{class: string, option: string, range: Range}>
     */
    public function formOptions(string $text): array
    {
        [$namespace, $imports] = $this->phpNames($text);
        $options = [];
        foreach ($this->calls($text) as $call) {
            $typeIndex = 'createNamed' === $call['name'] ? 1 : ('add' === $call['name'] ? 1 : 0);
            $optionsIndex = 'createNamed' === $call['name'] ? 3 : 2;
            if (!isset($call['arguments'][$typeIndex], $call['arguments'][$optionsIndex])) {
                continue;
            }
            if (!preg_match('/^\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class\b/', $call['arguments'][$typeIndex]['text'], $type)) {
                continue;
            }
            $class = $this->resolvePhpName($type[1], $namespace, $imports);
            foreach ($this->arrayKeys($text, $call['arguments'][$optionsIndex]) as $key) {
                $options[] = ['class' => $class, 'option' => $key['name'], 'range' => $key['range']];
            }
        }

        return $options;
    }

    /** @return list<array{constraint: string, option: string, range: Range}> */
    public function constraintOptions(string $text): array
    {
        $options = [];
        preg_match_all('/#\[\s*(?:Assert\\\\)?([A-Za-z_][A-Za-z0-9_]*)\s*\((.*?)\)\s*\]/s', $text, $attributes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($attributes as $attribute) {
            if (str_contains($attribute[2][0], 'new ')) {
                continue;
            }
            preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\s*:/', $attribute[2][0], $named, \PREG_OFFSET_CAPTURE);
            foreach ($named[1] as [$name, $offset]) {
                $absolute = $attribute[2][1] + $offset;
                $options[] = ['constraint' => $attribute[1][0], 'option' => $name, 'range' => $this->offsetRange($text, $absolute, \strlen($name))];
            }
        }

        return $options;
    }

    /** @return list<array{constraint: string, option: string, range: Range}> */
    public function yamlConstraintOptions(string $text): array
    {
        $options = [];
        foreach ($this->yaml->parse($text) as $occurrence) {
            $path = $occurrence->path();
            $count = \count($path);
            if ($count < 5 || 'properties' !== $path[1]) {
                continue;
            }
            $options[] = [
                'constraint' => $path[$count - 2],
                'option' => $path[$count - 1],
                'range' => $occurrence->keyRange(),
            ];
        }

        return $options;
    }

    /** @return list<MetadataSourceSymbol> */
    private function phpSymbols(string $uri, string $text): array
    {
        [$namespace, $imports] = $this->phpNames($text);
        $symbols = [];
        preg_match_all('/\b(?:final\s+|abstract\s+|readonly\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)[^\{]*\{/', $text, $classes, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($classes as $class) {
            $open = $class[0][1] + \strlen($class[0][0]) - 1;
            $close = $this->matching($text, $open, '{', '}') ?? \strlen($text);
            $body = substr($text, $open + 1, $close - $open - 1);
            $className = '' === $namespace ? $class[1][0] : $namespace.'\\'.$class[1][0];
            $symbols[] = new MetadataSourceSymbol(
                MetadataSymbolKind::MappedClass,
                $className,
                $uri,
                $this->offsetRange($text, $class[1][1], \strlen($class[1][0])),
                true,
            );
            if (preg_match('/\bextends\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)/', $class[0][0], $parent)
                && 'Symfony\\Component\\Validator\\Constraint' === $this->resolvePhpName($parent[1], $namespace, $imports)) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Constraint,
                    $class[1][0],
                    $uri,
                    $this->offsetRange($text, $class[1][1], \strlen($class[1][0])),
                    true,
                );
            }
            preg_match_all('/\b(?:public|protected|private|var)\s+(?:(?:readonly|static)\s+)*(?:[^$;=()]+\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/', $body, $properties, \PREG_OFFSET_CAPTURE);
            foreach ($properties[1] as [$property, $offset]) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Property,
                    $className.'::$'.$property,
                    $uri,
                    $this->offsetRange($text, $open + 1 + $offset, \strlen($property)),
                    true,
                );
            }
        }

        $groupsImported = 'Symfony\\Component\\Serializer\\Attribute\\Groups' === ($imports['Groups'] ?? null)
            || 'Symfony\\Component\\Serializer\\Annotation\\Groups' === ($imports['Groups'] ?? null);
        preg_match_all('/#\[\s*((?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\)?Groups)\s*\((.*?)\)\s*\]/s', $text, $groups, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($groups as $group) {
            if ('Groups' === $group[1][0] && !$groupsImported) {
                continue;
            }
            array_push($symbols, ...$this->quotedSymbols(MetadataSymbolKind::SerializerGroup, $uri, $text, $group[2][0], $group[2][1], true));
        }
        preg_match_all('/["\']groups["\']\s*=>\s*\[(.*?)\]/s', $text, $groupReferences, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($groupReferences as $group) {
            array_push($symbols, ...$this->quotedSymbols(MetadataSymbolKind::SerializerGroup, $uri, $text, $group[1][0], $group[1][1], false));
        }
        preg_match_all('/#\[\s*Assert\\\\([A-Za-z_][A-Za-z0-9_]*)/', $text, $constraintReferences, \PREG_OFFSET_CAPTURE);
        foreach ($constraintReferences[1] as [$name, $offset]) {
            $symbols[] = new MetadataSourceSymbol(MetadataSymbolKind::Constraint, $name, $uri, $this->offsetRange($text, $offset, \strlen($name)), false);
        }
        foreach ($imports as $alias => $className) {
            if (!str_contains($className, '\\Validator\\') && !str_contains($className, '\\Constraints\\')) {
                continue;
            }
            preg_match_all('/#\[\s*'.preg_quote($alias, '/').'\b/', $text, $references, \PREG_OFFSET_CAPTURE);
            foreach ($references[0] as [$reference, $offset]) {
                $nameOffset = $offset + strrpos($reference, $alias);
                $symbols[] = new MetadataSourceSymbol(MetadataSymbolKind::Constraint, $alias, $uri, $this->offsetRange($text, $nameOffset, \strlen($alias)), false);
            }
        }

        return $symbols;
    }

    /** @return list<MetadataSourceSymbol> */
    private function yamlSymbols(string $uri, string $text): array
    {
        $symbols = [];
        foreach ($this->yaml->parse($text) as $occurrence) {
            $path = $occurrence->path();
            if (1 === \count($path) && str_contains($path[0], '\\')) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::MappedClass,
                    $path[0],
                    $uri,
                    $occurrence->keyRange(),
                    false,
                );
            }
            if (3 === \count($path) && \in_array($path[1], ['properties', 'attributes'], true)) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Property,
                    $path[0].'::$'.$path[2],
                    $uri,
                    $occurrence->keyRange(),
                    false,
                );
            }
            if (4 === \count($path) && 'properties' === $path[1]) {
                $symbols[] = new MetadataSourceSymbol(
                    MetadataSymbolKind::Constraint,
                    $path[3],
                    $uri,
                    $occurrence->keyRange(),
                    false,
                );
            }
            if ([] === $path || 'groups' !== $path[array_key_last($path)]) {
                continue;
            }
            $start = $this->converter->toByteOffset($text, $occurrence->valueRange()->start());
            $end = $this->converter->toByteOffset($text, $occurrence->valueRange()->end());
            $value = substr($text, $start, $end - $start);
            preg_match_all('/[A-Za-z_][A-Za-z0-9_.:-]*/', $value, $names, \PREG_OFFSET_CAPTURE);
            foreach ($names[0] as [$name, $offset]) {
                $symbols[] = new MetadataSourceSymbol(MetadataSymbolKind::SerializerGroup, $name, $uri, $this->offsetRange($text, $start + $offset, \strlen($name)), true);
            }
        }

        return $symbols;
    }

    private function phpCompletionContext(string $text, int $offset): ?MetadataCompletionContext
    {
        $before = substr($text, 0, $offset);
        if (preg_match('/(?:["\']groups["\']\s*=>\s*\[[^\]]*|(?:[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\\\\)?Groups\s*\([^\)]*)["\']([A-Za-z_][A-Za-z0-9_.:-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::SerializerGroup, $match[1][0], $text, $match[1][1]);
        }
        $attribute = strrpos($before, '#[');
        if (false !== $attribute && !str_contains(substr($before, $attribute), ']')) {
            $expression = substr($before, $attribute + 2);
            if (preg_match('/^\s*(?:Assert\\\\)?([A-Za-z_][A-Za-z0-9_]*)\s*\((.*)$/s', $expression, $constraint) && preg_match('/(?:^|,)\s*([A-Za-z_][A-Za-z0-9_]*)$/', $constraint[2], $option, \PREG_OFFSET_CAPTURE)) {
                $optionOffset = $attribute + 2 + strpos($expression, $constraint[2]) + $option[1][1];

                return $this->context(MetadataCompletionKind::ConstraintOption, $option[1][0], $text, $optionOffset, $constraint[1]);
            }
            if (preg_match('/^\s*(?:Assert\\\\)?([A-Za-z_][A-Za-z0-9_]*)$/', $expression, $constraint, \PREG_OFFSET_CAPTURE)) {
                $name = $constraint[1][0];
                $nameOffset = $attribute + 2 + $constraint[1][1];

                return $this->context(MetadataCompletionKind::Constraint, $name, $text, $nameOffset);
            }
        }

        return $this->formCompletionContext($text, $offset);
    }

    private function formCompletionContext(string $text, int $offset): ?MetadataCompletionContext
    {
        [$namespace, $imports] = $this->phpNames($text);
        $formBuilders = $this->formBuilderVariables($text, $namespace, $imports);
        preg_match_all('/(?:(->)\s*)?\b(createForm|createNamed|add)\s*\(/', substr($text, 0, $offset), $calls, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach (array_reverse($calls) as $call) {
            if ('add' === $call[2][0] && !$this->isFormBuilderCall($text, $call[0][1], $formBuilders)) {
                continue;
            }
            $open = $call[0][1] + \strlen($call[0][0]) - 1;
            $close = $this->matching($text, $open, '(', ')');
            if (null !== $close && $close < $offset) {
                continue;
            }
            $arguments = $this->arguments(substr($text, $open + 1, $offset - $open - 1), $open + 1);
            $name = $call[2][0];
            $typeIndex = 'createNamed' === $name ? 1 : ('add' === $name ? 1 : 0);
            $optionsIndex = 'createNamed' === $name ? 3 : 2;
            if (\count($arguments) - 1 !== $optionsIndex || !isset($arguments[$typeIndex])) {
                continue;
            }
            $current = $arguments[$optionsIndex];
            if (!preg_match('/^\s*\[/', $current['text']) || !preg_match('/["\']([A-Za-z_][A-Za-z0-9_]*)$/', $current['text'], $prefix, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            if (!preg_match('/^\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::class\b/', $arguments[$typeIndex]['text'], $type)) {
                continue;
            }
            $class = $this->resolvePhpName($type[1], $namespace, $imports);
            $prefixOffset = $current['offset'] + $prefix[1][1];

            return $this->context(MetadataCompletionKind::FormOption, $prefix[1][0], $text, $prefixOffset, $class);
        }

        return null;
    }

    private function yamlCompletionContext(string $text, int $offset): ?MetadataCompletionContext
    {
        $before = substr($text, 0, $offset);
        $lineOffset = strrpos($before, "\n");
        $lineOffset = false === $lineOffset ? 0 : $lineOffset + 1;
        $line = substr($before, $lineOffset);
        $parent = $this->yamlParent(substr($before, 0, $lineOffset));
        if (2 === \count($parent) && \in_array($parent[1], ['properties', 'attributes'], true) && preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::Property, $match[1][0], $text, $lineOffset + $match[1][1], $parent[0]);
        }
        if (\count($parent) >= 4 && 'properties' === $parent[1] && preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::ConstraintOption, $match[1][0], $text, $lineOffset + $match[1][1], $parent[array_key_last($parent)]);
        }
        if (\count($parent) >= 3 && 'properties' === $parent[1] && preg_match('/^\s*-\s*([A-Za-z_][A-Za-z0-9_]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::Constraint, $match[1][0], $text, $lineOffset + $match[1][1]);
        }
        if ([] !== $parent && 'groups' === $parent[array_key_last($parent)] && preg_match('/^\s*-\s*["\']?([A-Za-z_][A-Za-z0-9_.:-]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::SerializerGroup, $match[1][0], $text, $lineOffset + $match[1][1]);
        }
        if (preg_match('/\bgroups\s*:\s*\[[^\]]*["\']?([A-Za-z_][A-Za-z0-9_.:-]*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context(MetadataCompletionKind::SerializerGroup, $match[1][0], $text, $lineOffset + $match[1][1]);
        }

        return null;
    }

    /** @return list<array{name: string, arguments: list<array{text: string, offset: int}>}> */
    private function calls(string $text): array
    {
        [$namespace, $imports] = $this->phpNames($text);
        $formBuilders = $this->formBuilderVariables($text, $namespace, $imports);
        preg_match_all('/(?:(->)\s*)?\b(createForm|createNamed|add)\s*\(/', $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        $calls = [];
        foreach ($matches as $match) {
            if ('add' === $match[2][0] && ('' === $match[1][0] || !$this->isFormBuilderCall($text, $match[0][1], $formBuilders))) {
                continue;
            }
            $open = $match[0][1] + \strlen($match[0][0]) - 1;
            $close = $this->matching($text, $open, '(', ')');
            if (null === $close) {
                continue;
            }
            $calls[] = ['name' => $match[2][0], 'arguments' => $this->arguments(substr($text, $open + 1, $close - $open - 1), $open + 1)];
        }

        return $calls;
    }

    /**
     * @param array<string, true> $variables
     */
    private function isFormBuilderCall(string $text, int $offset, array $variables): bool
    {
        $before = substr($text, 0, $offset);
        $statementStart = max((int) strrpos($before, ';'), (int) strrpos($before, '{'));
        $statement = substr($before, $statementStart);
        foreach (array_keys($variables) as $variable) {
            if (preg_match('/\\$'.preg_quote($variable, '/').'\b/', $statement)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $imports
     *
     * @return array<string, true>
     */
    private function formBuilderVariables(string $text, string $namespace, array $imports): array
    {
        $variables = [];
        preg_match_all('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ('Symfony\\Component\\Form\\FormBuilderInterface' === $this->resolvePhpName($match[1], $namespace, $imports)) {
                $variables[$match[2]] = true;
            }
        }

        return $variables;
    }

    /** @return list<array{text: string, offset: int}> */
    private function arguments(string $text, int $base): array
    {
        $arguments = [];
        $start = 0;
        $stack = [];
        $quote = null;
        $escaped = false;
        $length = \strlen($text);
        for ($index = 0; $index < $length; ++$index) {
            $character = $text[$index];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;
            } elseif (str_contains('([{', $character)) {
                $stack[] = $character;
            } elseif (str_contains(')]}', $character)) {
                array_pop($stack);
            } elseif (',' === $character && [] === $stack) {
                $arguments[] = ['text' => substr($text, $start, $index - $start), 'offset' => $base + $start];
                $start = $index + 1;
            }
        }
        $arguments[] = ['text' => substr($text, $start), 'offset' => $base + $start];

        return $arguments;
    }

    /**
     * @param array{text: string, offset: int} $argument
     *
     * @return list<array{name: string, range: Range}>
     */
    private function arrayKeys(string $document, array $argument): array
    {
        $text = $argument['text'];
        if (!preg_match('/^\s*\[/', $text, $open, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $keys = [];
        $depth = 0;
        $length = \strlen($text);
        for ($index = 0; $index < $length; ++$index) {
            $character = $text[$index];
            if ('[' === $character) {
                ++$depth;
                continue;
            }
            if (']' === $character) {
                --$depth;
                continue;
            }
            if (1 !== $depth || ('"' !== $character && "'" !== $character)) {
                continue;
            }
            $end = $index + 1;
            while ($end < $length && $text[$end] !== $character) {
                $end += '\\' === $text[$end] ? 2 : 1;
            }
            if ($end >= $length || !preg_match('/^\s*=>/', substr($text, $end + 1))) {
                $index = $end;
                continue;
            }
            $name = substr($text, $index + 1, $end - $index - 1);
            $absolute = $argument['offset'] + $index + 1;
            $keys[] = ['name' => $name, 'range' => $this->offsetRange($document, $absolute, \strlen($name))];
            $index = $end;
        }

        return $keys;
    }

    private function matching(string $text, int $open, string $opening, string $closing): ?int
    {
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = \strlen($text);
        for ($index = $open; $index < $length; ++$index) {
            $character = $text[$index];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ('"' === $character || "'" === $character) {
                $quote = $character;
            } elseif ($opening === $character) {
                ++$depth;
            } elseif ($closing === $character && 0 === --$depth) {
                return $index;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function yamlParent(string $text): array
    {
        $stack = [];
        preg_match_all('/^.*(?:\R|$)/m', $text, $lines);
        foreach ($lines[0] as $line) {
            $line = rtrim($line, "\r\n");
            if (!preg_match('/^(\s*)(?:-\s+)?([^:#][^:]*)\s*:\s*(.*)$/', $line, $match)) {
                continue;
            }
            $indent = \strlen($match[1]);
            foreach (array_keys($stack) as $level) {
                if ($level >= $indent) {
                    unset($stack[$level]);
                }
            }
            $parent = [];
            ksort($stack);
            foreach ($stack as $path) {
                $parent = $path;
            }
            if ('' === trim($match[3])) {
                $stack[$indent] = [...$parent, trim($match[2], " \t\"'")];
            }
        }
        $parent = [];
        ksort($stack);
        foreach ($stack as $path) {
            $parent = $path;
        }

        return $parent;
    }

    /** @return array{string, array<string, string>} */
    private function phpNames(string $text): array
    {
        $namespace = '';
        if (preg_match('/\bnamespace\s+([^;{]+)[;{]/', $text, $match)) {
            $namespace = trim($match[1]);
        }
        $imports = [];
        preg_match_all('/^\s*use\s+([^;]+);/m', $text, $matches);
        foreach ($matches[1] as $import) {
            if (str_contains($import, '{')) {
                continue;
            }
            $parts = preg_split('/\s+as\s+/i', trim($import));
            if (false === $parts || [] === $parts) {
                continue;
            }
            $className = ltrim($parts[0], '\\');
            $alias = $parts[1] ?? substr($className, (int) strrpos('\\'.$className, '\\'));
            $imports[$alias] = $className;
        }

        return [$namespace, $imports];
    }

    /** @param array<string, string> $imports */
    private function resolvePhpName(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $separator = strpos($name, '\\');
        $head = false === $separator ? $name : substr($name, 0, $separator);
        if (isset($imports[$head])) {
            return $imports[$head].(false === $separator ? '' : substr($name, $separator));
        }

        return '' === $namespace ? $name : $namespace.'\\'.$name;
    }

    /** @return list<MetadataSourceSymbol> */
    private function quotedSymbols(MetadataSymbolKind $kind, string $uri, string $text, string $fragment, int $base, bool $declaration): array
    {
        preg_match_all('/["\']([A-Za-z_][A-Za-z0-9_.:-]*)["\']/', $fragment, $matches, \PREG_OFFSET_CAPTURE);
        $symbols = [];
        foreach ($matches[1] as [$name, $offset]) {
            $symbols[] = new MetadataSourceSymbol($kind, $name, $uri, $this->offsetRange($text, $base + $offset, \strlen($name)), $declaration);
        }

        return $symbols;
    }

    private function context(MetadataCompletionKind $kind, string $prefix, string $text, int $offset, ?string $owner = null): MetadataCompletionContext
    {
        return new MetadataCompletionContext($kind, $prefix, $this->offsetRange($text, $offset, \strlen($prefix)), $owner);
    }

    private function offsetRange(string $text, int $offset, int $length): Range
    {
        return new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + $length));
    }

    /**
     * @param list<MetadataSourceSymbol> $symbols
     *
     * @return list<MetadataSourceSymbol>
     */
    private function unique(array $symbols): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $key = $symbol->kind()->value.'|'.$symbol->range()->start()->line().'|'.$symbol->range()->start()->character();
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
