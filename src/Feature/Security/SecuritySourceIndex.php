<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<SecuritySourceFacts> */
final class SecuritySourceIndex extends AbstractSourceFactsIndex
{
    /** @var array<string, array<string, list<SecuritySourceSymbol>>> */
    private array $symbols = [];

    /** @var array<string, list<string>> */
    private array $names = [];

    /** @var array<string, list<string>> */
    private array $declarationNames = [];

    /** @return list<SecuritySourceSymbol> */
    public function symbols(SecuritySymbolKind $kind, string $name): array
    {
        $this->derived();

        return $this->symbols[$kind->value][$name] ?? [];
    }

    /** @return list<string> */
    public function declarationNames(SecuritySymbolKind $kind): array
    {
        $this->derived();

        return $this->declarationNames[$kind->value] ?? [];
    }

    /** @return list<string> */
    public function names(SecuritySymbolKind $kind, bool $declarationsOnly = false): array
    {
        $this->derived();

        return $declarationsOnly ? $this->declarationNames[$kind->value] ?? [] : $this->names[$kind->value] ?? [];
    }

    protected function build(): void
    {
        $this->symbols = [];
        $names = [];
        $declarationNames = [];
        foreach ($this->facts() as $source) {
            foreach ($source->symbols as $symbol) {
                $kind = $symbol->kind->value;
                $name = $symbol->name;
                $this->symbols[$kind][$name][] = $symbol;
                $names[$kind][$name] = true;
                if ($symbol->declaration) {
                    $declarationNames[$kind][$name] = true;
                }
            }
        }

        $this->names = [];
        foreach ($names as $kind => $kindNames) {
            $this->names[$kind] = array_keys($kindNames);
            sort($this->names[$kind]);
        }
        $this->declarationNames = [];
        foreach ($declarationNames as $kind => $kindNames) {
            $this->declarationNames[$kind] = array_keys($kindNames);
            sort($this->declarationNames[$kind]);
        }
    }
}
