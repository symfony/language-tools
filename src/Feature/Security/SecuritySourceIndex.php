<?php

namespace Symfony\Lsp\Feature\Security;

final class SecuritySourceIndex
{
    /** @var array<string, SecuritySourceFacts> */
    private array $sources = [];
    /** @var array<string, SecuritySourceFacts> */
    private array $overlays = [];

    public function replace(SecuritySourceFacts ...$sources): void
    {
        $this->sources = [];
        foreach ($sources as $source) {
            $this->sources[$source->uri()] = $source;
        }
    }

    public function overlay(SecuritySourceFacts $source): void
    {
        $this->overlays[$source->uri()] = $source;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /** @return list<SecuritySourceSymbol> */
    public function symbols(SecuritySymbolKind $kind, string $name): array
    {
        $symbols = [];
        foreach ($this->sources() as $source) {
            foreach ($source->symbols() as $symbol) {
                if ($symbol->kind() === $kind && $symbol->name() === $name) {
                    $symbols[] = $symbol;
                }
            }
        }

        return $symbols;
    }

    /** @return list<string> */
    public function declarationNames(SecuritySymbolKind $kind): array
    {
        return $this->names($kind, true);
    }

    /** @return list<string> */
    public function names(SecuritySymbolKind $kind, bool $declarationsOnly = false): array
    {
        $names = [];
        foreach ($this->sources() as $source) {
            foreach ($source->symbols() as $symbol) {
                if ($symbol->kind() === $kind && (!$declarationsOnly || $symbol->isDeclaration())) {
                    $names[$symbol->name()] = true;
                }
            }
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /** @return list<SecuritySourceFacts> */
    private function sources(): array
    {
        return [...array_values(array_diff_key($this->sources, $this->overlays)), ...array_values($this->overlays)];
    }
}
