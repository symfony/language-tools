<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\SourceSymbolTable;

/** @extends AbstractSourceFactsIndex<SecuritySourceFacts> */
final class SecuritySourceIndex extends AbstractSourceFactsIndex
{
    /** @var SourceSymbolTable<SecuritySourceSymbol> */
    private SourceSymbolTable $symbols;

    /** @return list<SecuritySourceSymbol> */
    public function symbols(SecuritySymbolKind $kind, string $name): array
    {
        $this->derived();

        return $this->symbols->symbols($kind->value, $name);
    }

    /** @return list<string> */
    public function declarationNames(SecuritySymbolKind $kind): array
    {
        $this->derived();

        return $this->symbols->declarationNames($kind->value);
    }

    /** @return list<string> */
    public function names(SecuritySymbolKind $kind, bool $declarationsOnly = false): array
    {
        $this->derived();

        return $declarationsOnly ? $this->symbols->declarationNames($kind->value) : $this->symbols->names($kind->value);
    }

    protected function build(): void
    {
        $this->symbols = new SourceSymbolTable();
        foreach ($this->facts() as $facts) {
            foreach ($facts->symbols as $symbol) {
                $this->symbols->add($symbol->kind->value, $symbol);
            }
        }
    }
}
