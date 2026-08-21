<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Feature\Configuration\YamlConfigurationParser;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class MessengerExtractor
{
    public function __construct(private readonly PositionConverter $converter, private readonly PhpParserInterface $parser, private readonly YamlConfigurationParser $yaml)
    {
    }

    public function extract(string $uri, string $languageId, string $text): MessengerSourceFacts
    {
        /** @var list<MessengerSourceSymbol> $symbols */
        $symbols = [];
        $parents = [];
        $handlers = [];
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
            $php = $this->parser->parse($text);
            preg_match_all('/#\[\s*(?:[\\\\A-Za-z_][\\\\A-Za-z0-9_]*\\\\)*AsMessageHandler\b(?:\([^)]*\))?\s*\]/s', $text, $handlerAttributes);
            $handlers = $handlerAttributes[0];
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
            $parents = $this->phpParents($text, $php);
            $busVariables = $this->messengerBusVariables($text, $php);
            preg_match_all('/(?:\$([A-Za-z_][A-Za-z0-9_]*)|\$this\s*->\s*([A-Za-z_][A-Za-z0-9_]*))\s*->\s*dispatch\s*\(\s*new\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)/', $text, $dispatches, \PREG_OFFSET_CAPTURE);
            foreach ($dispatches[3] as $index => [$name, $offset]) {
                $variable = '' !== $dispatches[2][$index][0] ? $dispatches[2][$index][0] : $dispatches[1][$index][0];
                if (isset($busVariables[$variable])) {
                    $symbols[] = $this->symbol(MessengerSymbolKind::Message, $php->resolveName($name), $uri, $text, $offset, false, \strlen($name));
                }
            }
            preg_match_all('/new\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\(\s*new\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)/', $text, $envelopes, \PREG_OFFSET_CAPTURE);
            foreach ($envelopes[2] as $index => [$name, $offset]) {
                if ('Symfony\\Component\\Messenger\\Envelope' === $php->resolveName($envelopes[1][$index][0])) {
                    $symbols[] = $this->symbol(MessengerSymbolKind::Message, $php->resolveName($name), $uri, $text, $offset, false, \strlen($name));
                }
            }
            preg_match_all('/AsMessageHandler\s*\([^)]*\bhandles\s*:\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::class/', $text, $handledMessages, \PREG_OFFSET_CAPTURE);
            foreach ($handledMessages[1] as [$name, $offset]) {
                $symbols[] = $this->symbol(MessengerSymbolKind::Message, $php->resolveName($name), $uri, $text, $offset, false, \strlen($name));
            }
        }

        return new MessengerSourceFacts($uri, $this->unique($symbols), $parents, $handlers);
    }

    /** @return list<MessengerSourceSymbol> */
    private function yamlSymbols(string $uri, string $text): array
    {
        $symbols = [];
        foreach ($this->yaml->parse($text) as $occurrence) {
            $path = $occurrence->path();
            $parent = \array_slice($path, 0, -1);
            $key = [] === $path ? '' : $path[\count($path) - 1];
            $keyOffset = $this->converter->toByteOffset($text, $occurrence->keyRange()->start());
            $declarationKind = match (\array_slice($parent, -3)) {
                ['framework', 'messenger', 'buses'] => MessengerSymbolKind::Bus,
                ['framework', 'messenger', 'transports'] => MessengerSymbolKind::Transport,
                default => null,
            };
            if (null !== $declarationKind) {
                $symbols[] = $this->symbol($declarationKind, $key, $uri, $text, $keyOffset, true);
            }
            if (\array_slice($parent, -3) !== ['framework', 'messenger', 'routing']) {
                continue;
            }
            $symbols[] = $this->symbol(MessengerSymbolKind::Message, ltrim($key, '\\'), $uri, $text, $keyOffset, false, \strlen($key));
            $valueOffset = $this->converter->toByteOffset($text, $occurrence->valueRange()->start());
            preg_match_all('/[A-Za-z_][A-Za-z0-9_.-]*/', $occurrence->value(), $names, \PREG_OFFSET_CAPTURE);
            foreach ($names[0] as [$name, $relativeOffset]) {
                $symbols[] = $this->symbol(MessengerSymbolKind::Transport, $name, $uri, $text, $valueOffset + $relativeOffset, false);
            }
        }

        return $symbols;
    }

    private function symbol(MessengerSymbolKind $kind, string $name, string $uri, string $text, int $offset, bool $declaration, ?int $length = null): MessengerSourceSymbol
    {
        return new MessengerSourceSymbol($kind, $name, $uri, new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + ($length ?? \strlen($name)))), $declaration);
    }

    /** @return array<string, list<string>> */
    private function phpParents(string $text, PhpDocument $php): array
    {
        $parents = [];
        preg_match_all('/\b(class|interface|enum)\s+([A-Za-z_][A-Za-z0-9_]*)\s*([^{}]*)\{/', $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $className = $php->resolveName($match[2]);
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
            $resolved = [];
            foreach ($types as $type) {
                $resolved[] = $php->resolveName($type);
            }
            $parents[$className] = array_values(array_unique($resolved));
        }

        return $parents;
    }

    /** @return array<string, true> */
    private function messengerBusVariables(string $text, PhpDocument $php): array
    {
        $variables = [];
        preg_match_all('/(\\??[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            $type = $php->resolveName(ltrim($match[1], '?'));
            if (\in_array($type, ['Symfony\\Component\\Messenger\\MessageBus', 'Symfony\\Component\\Messenger\\MessageBusInterface'], true)) {
                $variables[$match[2]] = true;
            }
        }

        return $variables;
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
