<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class MessengerExtractor
{
    public function __construct(private readonly PositionConverter $converter)
    {
    }

    public function extract(string $uri, string $languageId, string $text): MessengerSourceFacts
    {
        /** @var list<MessengerSourceSymbol> $symbols */
        $symbols = [];
        $parents = [];
        if ('yaml' === $languageId) {
            array_push($symbols, ...$this->yamlSymbols($uri, $text));
        }
        if ('yaml' === $languageId) {
            foreach ([
                [MessengerSymbolKind::Bus, '/(?:\bbus|default_bus)\s*:\s*["\']?([A-Za-z_][A-Za-z0-9_.-]*)/'],
                [MessengerSymbolKind::Transport, '/(?:fromTransport|from_transport|failure_transport)\s*:\s*["\']?([A-Za-z_][A-Za-z0-9_.-]*)/'],
            ] as [$kind, $pattern]) {
                preg_match_all($pattern, $text, $matches, \PREG_OFFSET_CAPTURE);
                foreach ($matches[1] as [$name, $offset]) {
                    $symbols[] = $this->symbol($kind, $name, $uri, $text, $offset, false);
                }
            }
        }
        if ('php' === $languageId) {
            preg_match_all('/AsMessageHandler\s*\(([^)]*)\)/s', $text, $attributes, \PREG_OFFSET_CAPTURE);
            foreach ($attributes[1] as [$arguments, $argumentsOffset]) {
                foreach ([
                    [MessengerSymbolKind::Bus, '/\bbus\s*:\s*["\']([A-Za-z_][A-Za-z0-9_.-]*)/'],
                    [MessengerSymbolKind::Transport, '/\bfromTransport\s*:\s*["\']([A-Za-z_][A-Za-z0-9_.-]*)/'],
                ] as [$kind, $pattern]) {
                    preg_match_all($pattern, $arguments, $matches, \PREG_OFFSET_CAPTURE);
                    foreach ($matches[1] as [$name, $offset]) {
                        $symbols[] = $this->symbol($kind, $name, $uri, $text, $argumentsOffset + $offset, false);
                    }
                }
            }
            preg_match_all('/BusNameStamp\s*\(\s*["\']([A-Za-z_][A-Za-z0-9_.-]*)/', $text, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$name, $offset]) {
                $symbols[] = $this->symbol(MessengerSymbolKind::Bus, $name, $uri, $text, $offset, false);
            }
            [$namespace, $imports] = $this->phpNames($text);
            $parents = $this->phpParents($text, $namespace, $imports);
            $busVariables = $this->messengerBusVariables($text, $namespace, $imports);
            preg_match_all('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*dispatch\s*\(\s*new\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)/', $text, $dispatches, \PREG_OFFSET_CAPTURE);
            foreach ($dispatches[3] as $index => [$name, $offset]) {
                $variable = '' !== $dispatches[2][$index][0] ? $dispatches[2][$index][0] : $dispatches[1][$index][0];
                if (isset($busVariables[$variable])) {
                    $symbols[] = $this->symbol(MessengerSymbolKind::Message, $this->resolvePhpName($name, $namespace, $imports), $uri, $text, $offset, false, \strlen($name));
                }
            }
            preg_match_all('/new\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\(\s*new\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)/', $text, $envelopes, \PREG_OFFSET_CAPTURE);
            foreach ($envelopes[2] as $index => [$name, $offset]) {
                if ('Symfony\\Component\\Messenger\\Envelope' === $this->resolvePhpName($envelopes[1][$index][0], $namespace, $imports)) {
                    $symbols[] = $this->symbol(MessengerSymbolKind::Message, $this->resolvePhpName($name, $namespace, $imports), $uri, $text, $offset, false, \strlen($name));
                }
            }
            preg_match_all('/AsMessageHandler\s*\([^)]*\bhandles\s*:\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class/', $text, $handledMessages, \PREG_OFFSET_CAPTURE);
            foreach ($handledMessages[1] as [$name, $offset]) {
                $symbols[] = $this->symbol(MessengerSymbolKind::Message, $this->resolvePhpName($name, $namespace, $imports), $uri, $text, $offset, false, \strlen($name));
            }
        }

        return new MessengerSourceFacts($uri, $this->unique($symbols), $parents);
    }

    /** @return list<MessengerSourceSymbol> */
    private function yamlSymbols(string $uri, string $text): array
    {
        $symbols = [];
        $stack = [];
        preg_match_all('/^.*(?:\R|$)/m', $text, $lines, \PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as [$line, $lineOffset]) {
            $line = rtrim($line, "\r\n");
            if (!preg_match('/^(\s*)([^#:\s][^:#]*?)\s*:\s*(.*)$/', $line, $match, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $indent = \strlen($match[1][0]);
            $key = trim($match[2][0], " \t\"'");
            $value = trim((string) preg_replace('/\s+#.*$/', '', $match[3][0]));
            foreach (array_keys($stack) as $level) {
                if ($level >= $indent) {
                    unset($stack[$level]);
                }
            }
            ksort($stack);
            $parent = [];
            foreach ($stack as $path) {
                $parent = $path;
            }
            $path = [...$parent, $key];
            if ('' === $value) {
                $stack[$indent] = $path;
            }
            $declarationKind = match (\array_slice($parent, -3)) {
                ['framework', 'messenger', 'buses'] => MessengerSymbolKind::Bus,
                ['framework', 'messenger', 'transports'] => MessengerSymbolKind::Transport,
                default => null,
            };
            $keyOffset = $lineOffset + $match[2][1] + (int) strpos($match[2][0], $key);
            if (null !== $declarationKind) {
                $symbols[] = $this->symbol($declarationKind, $key, $uri, $text, $keyOffset, true);
            }
            if (\array_slice($parent, -3) === ['framework', 'messenger', 'routing']) {
                $symbols[] = $this->symbol(MessengerSymbolKind::Message, ltrim($key, '\\'), $uri, $text, $keyOffset, false, \strlen($key));
                preg_match_all('/[A-Za-z_][A-Za-z0-9_.-]*/', trim($value, "[] \t\"'"), $names, \PREG_OFFSET_CAPTURE);
                foreach ($names[0] as [$name, $relativeOffset]) {
                    $valueOffset = $lineOffset + $match[3][1] + strpos($match[3][0], $name, $relativeOffset);
                    $symbols[] = $this->symbol(MessengerSymbolKind::Transport, $name, $uri, $text, $valueOffset, false);
                }
            }
        }

        return $symbols;
    }

    private function symbol(MessengerSymbolKind $kind, string $name, string $uri, string $text, int $offset, bool $declaration, ?int $length = null): MessengerSourceSymbol
    {
        return new MessengerSourceSymbol($kind, $name, $uri, new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + ($length ?? \strlen($name)))), $declaration);
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

    /**
     * @param array<string, string> $imports
     *
     * @return array<string, list<string>>
     */
    private function phpParents(string $text, string $namespace, array $imports): array
    {
        $parents = [];
        preg_match_all('/\b(class|interface|enum)\s+([A-Za-z_][A-Za-z0-9_]*)\s*([^{}]*)\{/', $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $className = '' === $namespace ? $match[2] : $namespace.'\\'.$match[2];
            $typeLists = [];
            if (preg_match('/\bextends\s+([^\s,{]+(?:\s*,\s*[^\s,{]+)*)/', $match[3], $extends)) {
                $typeLists[] = $extends[1];
            }
            if (preg_match('/\bimplements\s+([^\{]+)/', $match[3], $implements)) {
                $typeLists[] = trim($implements[1]);
            }
            $types = [];
            foreach ($typeLists as $typeList) {
                $splitTypes = preg_split('/\s*,\s*/', $typeList);
                if (false !== $splitTypes) {
                    array_push($types, ...$splitTypes);
                }
            }
            $parents[$className] = array_values(array_unique(array_map(fn (string $type): string => $this->resolvePhpName($type, $namespace, $imports), $types)));
        }

        return $parents;
    }

    /**
     * @param array<string, string> $imports
     *
     * @return array<string, true>
     */
    private function messengerBusVariables(string $text, string $namespace, array $imports): array
    {
        $variables = [];
        preg_match_all('/(\\??[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $type = $this->resolvePhpName(ltrim($match[1], '?'), $namespace, $imports);
            if (\in_array($type, ['Symfony\\Component\\Messenger\\MessageBus', 'Symfony\\Component\\Messenger\\MessageBusInterface'], true)) {
                $variables[$match[2]] = true;
            }
        }

        return $variables;
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

    /**
     * @param list<MessengerSourceSymbol> $symbols
     *
     * @return list<MessengerSourceSymbol>
     */
    private function unique(array $symbols): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $key = $symbol->kind()->name.'|'.$symbol->range()->start()->line().'|'.$symbol->range()->start()->character();
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
