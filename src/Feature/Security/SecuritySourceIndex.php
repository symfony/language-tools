<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<SecuritySourceFacts> */
final class SecuritySourceIndex extends AbstractSourceFactsIndex
{
    /** @return list<SecuritySourceSymbol> */
    public function symbols(SecuritySymbolKind $kind, string $name): array
    {
        $symbols = [];
        foreach ($this->facts() as $source) {
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
        foreach ($this->facts() as $source) {
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
}
