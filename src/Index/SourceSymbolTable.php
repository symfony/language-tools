<?php

namespace Symfony\Lsp\Index;

/** @template TSymbol of NamedSourceSymbolInterface */
final class SourceSymbolTable
{
    /** @var array<string, list<TSymbol>> */
    private array $symbols = [];

    /** @var array<string, array<string, list<TSymbol>>> */
    private array $symbolsByName = [];

    /** @var array<string, list<string>>|null */
    private ?array $names = null;

    /** @var array<string, list<string>>|null */
    private ?array $declarationNames = null;

    /** @param array<string, \Closure(string): string> $nameKeys */
    public function __construct(
        private readonly array $nameKeys = [],
    ) {
    }

    /** @param TSymbol $symbol */
    public function add(string $kind, NamedSourceSymbolInterface $symbol): void
    {
        $this->symbols[$kind][] = $symbol;
        $this->symbolsByName[$kind][$this->key($kind, $symbol->name)][] = $symbol;
        $this->names = null;
        $this->declarationNames = null;
    }

    /** @return list<TSymbol> */
    public function symbols(string $kind, ?string $name = null): array
    {
        return null === $name ? $this->symbols[$kind] ?? [] : $this->symbolsByName[$kind][$this->key($kind, $name)] ?? [];
    }

    /** @return list<string> */
    public function names(string $kind): array
    {
        return ($this->names ??= $this->sortedNames(false))[$kind] ?? [];
    }

    /** @return list<string> */
    public function declarationNames(string $kind): array
    {
        return ($this->declarationNames ??= $this->sortedNames(true))[$kind] ?? [];
    }

    /** @return array<string, list<string>> */
    private function sortedNames(bool $declarationsOnly): array
    {
        $names = [];
        foreach ($this->symbolsByName as $kind => $symbolsByName) {
            $kindNames = [];
            foreach ($symbolsByName as $symbols) {
                if ($declarationsOnly && !array_any($symbols, static fn (NamedSourceSymbolInterface $symbol): bool => $symbol->declaration)) {
                    continue;
                }
                $kindNames[] = $symbols[0]->name;
            }
            sort($kindNames);
            $names[$kind] = $kindNames;
        }

        return $names;
    }

    private function key(string $kind, string $name): string
    {
        return isset($this->nameKeys[$kind]) ? ($this->nameKeys[$kind])($name) : $name;
    }
}
