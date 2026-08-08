<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class EventExtractor
{
    public function __construct(private readonly PositionConverter $converter)
    {
    }

    public function extract(string $uri, string $languageId, string $text): EventSourceFacts
    {
        if ('php' === $languageId) {
            return $this->extractPhp($uri, $text);
        }
        if ('yaml' === $languageId) {
            return new EventSourceFacts($uri, $this->yamlSymbols($uri, $text));
        }

        return new EventSourceFacts($uri, []);
    }

    public function completionPrefix(string $languageId, string $text, int $offset): ?string
    {
        $before = substr($text, 0, $offset);
        if ('php' === $languageId) {
            if (preg_match('/AsEventListener\s*\([^)]*\bevent\s*:\s*["\']([^"\']*)$/s', $before, $match)) {
                return $match[1];
            }
            preg_match_all('/function\s+getSubscribedEvents\s*\([^)]*\)[^{]*\{/', $text, $subscriberMethods, \PREG_OFFSET_CAPTURE);
            foreach ($subscriberMethods[0] as [$declaration, $declarationOffset]) {
                $open = $declarationOffset + \strlen($declaration) - 1;
                if ($offset <= $open || $offset > $this->matchingBrace($text, $open)) {
                    continue;
                }
                $bodyBefore = substr($text, $open + 1, $offset - $open - 1);
                if (preg_match('/(?:\[|,)\s*["\']([^"\']*)$/s', $bodyBefore, $match)) {
                    return $match[1];
                }
            }
            [$namespace, $imports] = $this->phpNames($text);
            $dispatchers = $this->eventDispatcherVariables($text, $namespace, $imports);
            if (preg_match('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*(?:addListener\s*\(\s*|dispatch\s*\([^,\r\n]+,\s*)["\']([^"\']*)$/', $before, $match)) {
                $variable = '' !== $match[2] ? $match[2] : $match[1];
                if (isset($dispatchers[$variable])) {
                    return $match[3];
                }
            }
        }
        if ('yaml' === $languageId) {
            return $this->yamlCompletionPrefix($before);
        }

        return null;
    }

    private function extractPhp(string $uri, string $text): EventSourceFacts
    {
        $symbols = [];
        $invalidListenerMethods = [];
        [$namespace, $imports] = $this->phpNames($text);
        preg_match_all('/#\[\s*(?:[\\\\A-Za-z_][\\\\A-Za-z0-9_]*\\\\)*AsEventListener\b(?:\([^)]*\))?\s*\]/s', $text, $listenerAttributes);
        $listeners = $listenerAttributes[0];

        preg_match_all('/AsEventListener\s*\(([^)]*)\)/s', $text, $attributes, \PREG_OFFSET_CAPTURE);
        foreach ($attributes[1] as [$arguments, $argumentsOffset]) {
            if (preg_match('/\bevent\s*:\s*["\']([^"\']+)/', $arguments, $match, \PREG_OFFSET_CAPTURE)) {
                $symbols[] = $this->symbol($match[1][0], $uri, $text, $argumentsOffset + $match[1][1], true);
            } elseif (preg_match('/\bevent\s*:\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class/', $arguments, $match, \PREG_OFFSET_CAPTURE)) {
                $symbols[] = $this->symbol($this->resolvePhpName($match[1][0], $namespace, $imports), $uri, $text, $argumentsOffset + $match[1][1], true, \strlen($match[1][0]));
            }
        }

        $dispatchers = $this->eventDispatcherVariables($text, $namespace, $imports);
        preg_match_all('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*dispatch\s*\(\s*new\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)/', $text, $dispatches, \PREG_OFFSET_CAPTURE);
        foreach ($dispatches[3] as $index => [$name, $offset]) {
            $variable = '' !== $dispatches[2][$index][0] ? $dispatches[2][$index][0] : $dispatches[1][$index][0];
            if (isset($dispatchers[$variable])) {
                $symbols[] = $this->symbol($this->resolvePhpName($name, $namespace, $imports), $uri, $text, $offset, false, \strlen($name));
            }
        }
        preg_match_all('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*(?:addListener\s*\(\s*|dispatch\s*\([^,\r\n]+,\s*)["\']([^"\']+)/', $text, $namedEvents, \PREG_OFFSET_CAPTURE);
        foreach ($namedEvents[3] as $index => [$name, $offset]) {
            $variable = '' !== $namedEvents[2][$index][0] ? $namedEvents[2][$index][0] : $namedEvents[1][$index][0];
            if (isset($dispatchers[$variable])) {
                $symbols[] = $this->symbol($name, $uri, $text, $offset, false);
            }
        }

        preg_match_all('/function\s+getSubscribedEvents\s*\([^)]*\)[^{]*\{/', $text, $subscriberMethods, \PREG_OFFSET_CAPTURE);
        foreach ($subscriberMethods[0] as [$declaration, $declarationOffset]) {
            $open = $declarationOffset + \strlen($declaration) - 1;
            $close = $this->matchingBrace($text, $open);
            $body = substr($text, $open + 1, $close - $open - 1);
            preg_match_all('/["\']([^"\']+)["\']\s*=>/', $body, $stringEvents, \PREG_OFFSET_CAPTURE);
            foreach ($stringEvents[1] as [$name, $offset]) {
                $symbols[] = $this->symbol($name, $uri, $text, $open + 1 + $offset, true);
            }
            preg_match_all('/([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class\s*=>/', $body, $classEvents, \PREG_OFFSET_CAPTURE);
            foreach ($classEvents[1] as [$name, $offset]) {
                $symbols[] = $this->symbol($this->resolvePhpName($name, $namespace, $imports), $uri, $text, $open + 1 + $offset, true, \strlen($name));
            }
        }

        preg_match_all('/#\[\s*(?:[\\\\A-Za-z_][\\\\A-Za-z0-9_]*\\\\)*AsEventListener\s*\((.*?)\)\s*\]\s*(?:(?:final|abstract|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)([^\{]*)\{/s', $text, $classListeners, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($classListeners as $listener) {
            if (!preg_match('/\bmethod\s*:\s*["\']([^"\']+)["\']/', $listener[1][0], $method, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $open = $listener[0][1] + \strlen($listener[0][0]) - 1;
            $close = $this->matchingBrace($text, $open);
            $body = substr($text, $open + 1, $close - $open - 1);
            if (!str_contains($listener[3][0], 'extends') && !preg_match('/\buse\s+[^;]+;/', $body) && !preg_match('/\bfunction\s+'.preg_quote($method[1][0], '/').'\s*\(/', $body)) {
                $className = '' === $namespace ? $listener[2][0] : $namespace.'\\'.$listener[2][0];
                $methodOffset = $listener[1][1] + $method[1][1];
                $invalidListenerMethods[] = new InvalidEventListenerMethod($className, $method[1][0], new Range($this->converter->toPosition($text, $methodOffset), $this->converter->toPosition($text, $methodOffset + \strlen($method[1][0]))));
            }
        }

        return new EventSourceFacts($uri, $this->unique($symbols), $invalidListenerMethods, $listeners);
    }

    private function yamlCompletionPrefix(string $text): ?string
    {
        $listenerIndent = null;
        $lines = preg_split('/\R/', $text);
        if (false === $lines) {
            return null;
        }
        $lastLine = array_key_last($lines);
        foreach ($lines as $index => $line) {
            if (preg_match('/^(\s*)-\s*(?:\{\s*)?name\s*:\s*["\']?kernel\.event_listener["\']?(.*)$/', $line, $tag)) {
                $listenerIndent = \strlen($tag[1]);
                if ($index === $lastLine && preg_match('/\bevent\s*:\s*["\']?([A-Za-z0-9_.\\\\-]*)$/', $tag[2], $event)) {
                    return $event[1];
                }
                continue;
            }
            if (null === $listenerIndent || !preg_match('/^(\s*)/', $line, $indentMatch)) {
                continue;
            }
            $indent = \strlen($indentMatch[1]);
            if ('' !== trim($line) && $indent <= $listenerIndent) {
                $listenerIndent = null;
                continue;
            }
            if ($index === $lastLine && preg_match('/^\s*event\s*:\s*["\']?([A-Za-z0-9_.\\\\-]*)$/', $line, $event)) {
                return $event[1];
            }
        }

        return null;
    }

    /** @return list<EventSourceSymbol> */
    private function yamlSymbols(string $uri, string $text): array
    {
        $symbols = [];
        $listenerIndent = null;
        preg_match_all('/^.*(?:\R|$)/m', $text, $lines, \PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as [$line, $lineOffset]) {
            $line = rtrim($line, "\r\n");
            if (preg_match('/^(\s*)-\s*(?:\{\s*)?name\s*:\s*["\']?kernel\.event_listener["\']?(.*)$/', $line, $tag)) {
                $listenerIndent = \strlen($tag[1]);
                if (preg_match('/\bevent\s*:\s*["\']?([A-Za-z0-9_.\\\\-]+)/', $tag[2], $event, \PREG_OFFSET_CAPTURE)) {
                    $offset = $lineOffset + (int) strpos($line, $tag[2]) + $event[1][1];
                    $symbols[] = $this->symbol($event[1][0], $uri, $text, $offset, true);
                }
                continue;
            }
            if (null === $listenerIndent || !preg_match('/^(\s*)/', $line, $indentMatch)) {
                continue;
            }
            $indent = \strlen($indentMatch[1]);
            if ('' !== trim($line) && $indent <= $listenerIndent) {
                $listenerIndent = null;
                continue;
            }
            if (preg_match('/^\s*event\s*:\s*["\']?([A-Za-z0-9_.\\\\-]+)/', $line, $event, \PREG_OFFSET_CAPTURE)) {
                $symbols[] = $this->symbol($event[1][0], $uri, $text, $lineOffset + $event[1][1], true);
            }
        }

        return $symbols;
    }

    private function symbol(string $name, string $uri, string $text, int $offset, bool $declaration, ?int $length = null): EventSourceSymbol
    {
        return new EventSourceSymbol(ltrim($name, '\\'), $uri, new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + ($length ?? \strlen($name)))), $declaration);
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
     * @return array<string, true>
     */
    private function eventDispatcherVariables(string $text, string $namespace, array $imports): array
    {
        $variables = [];
        preg_match_all('/(\\??[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $type = $this->resolvePhpName(ltrim($match[1], '?'), $namespace, $imports);
            if (\in_array($type, ['Symfony\\Component\\EventDispatcher\\EventDispatcher', 'Symfony\\Component\\EventDispatcher\\EventDispatcherInterface', 'Symfony\\Contracts\\EventDispatcher\\EventDispatcherInterface'], true)) {
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

    private function matchingBrace(string $text, int $open): int
    {
        $depth = 0;
        for ($offset = $open, $length = \strlen($text); $offset < $length; ++$offset) {
            if ('{' === $text[$offset]) {
                ++$depth;
            } elseif ('}' === $text[$offset] && 0 === --$depth) {
                return $offset;
            }
        }

        return \strlen($text);
    }

    /**
     * @param list<EventSourceSymbol> $symbols
     *
     * @return list<EventSourceSymbol>
     */
    private function unique(array $symbols): array
    {
        $unique = [];
        foreach ($symbols as $symbol) {
            $key = $symbol->name().'|'.$symbol->range()->start()->line().'|'.$symbol->range()->start()->character();
            $unique[$key] = $symbol;
        }

        return array_values($unique);
    }
}
