<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<SecuritySourceFacts> */
final class SecuritySourceIndex extends AbstractSourceFactsIndex
{
    private bool $indexed = false;

    /** @var array<string, array<string, list<SecuritySourceSymbol>>> */
    private array $symbols = [];

    /** @var array<string, list<string>> */
    private array $names = [];

    /** @var array<string, list<string>> */
    private array $declarationNames = [];

    /** @return list<SecuritySourceSymbol> */
    public function symbols(SecuritySymbolKind $kind, string $name): array
    {
        $this->index();

        return $this->symbols[$kind->value][$name] ?? [];
    }

    /** @return list<string> */
    public function declarationNames(SecuritySymbolKind $kind): array
    {
        $this->index();

        return $this->declarationNames[$kind->value] ?? [];
    }

    /** @return list<string> */
    public function names(SecuritySymbolKind $kind, bool $declarationsOnly = false): array
    {
        $this->index();

        return $declarationsOnly ? $this->declarationNames[$kind->value] ?? [] : $this->names[$kind->value] ?? [];
    }

    protected function factsChanged(): void
    {
        $this->indexed = false;
    }

    private function index(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->symbols = [];
        $names = [];
        $declarationNames = [];
        foreach ($this->facts() as $source) {
            foreach ($source->symbols() as $symbol) {
                $kind = $symbol->kind()->value;
                $name = $symbol->name();
                $this->symbols[$kind][$name][] = $symbol;
                $names[$kind][$name] = true;
                if ($symbol->isDeclaration()) {
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
        $this->indexed = true;
    }
}
