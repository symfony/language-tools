<?php

namespace Symfony\Lsp\Index;

/**
 * Indexes named source symbols by kind and name.
 *
 * @template TSymbol of NamedSourceSymbolInterface
 */
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

    /** @param TSymbol $symbol */
    public function add(string $kind, NamedSourceSymbolInterface $symbol): void
    {
        $this->symbols[$kind][] = $symbol;
        $this->symbolsByName[$kind][$symbol->name][] = $symbol;
        $this->names = null;
        $this->declarationNames = null;
    }

    /** @return list<TSymbol> */
    public function symbols(string $kind, ?string $name = null): array
    {
        return null === $name ? $this->symbols[$kind] ?? [] : $this->symbolsByName[$kind][$name] ?? [];
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
            foreach ($symbolsByName as $name => $symbols) {
                if ($declarationsOnly && !array_any($symbols, static fn (NamedSourceSymbolInterface $symbol): bool => $symbol->declaration)) {
                    continue;
                }
                // numeric names come back as integer array keys
                $kindNames[] = (string) $name;
            }
            sort($kindNames);
            $names[$kind] = $kindNames;
        }

        return $names;
    }
}
