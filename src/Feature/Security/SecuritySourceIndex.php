<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\SourceFactsStore;

final class SecuritySourceIndex
{
    /** @var SourceFactsStore<SecuritySourceFacts> */
    private readonly SourceFactsStore $sources;

    public function __construct()
    {
        $this->sources = new SourceFactsStore();
    }

    public function replace(SecuritySourceFacts ...$sources): void
    {
        $this->sources->replaceSaved(...$sources);
    }

    public function replaceSource(SecuritySourceFacts $source): void
    {
        $this->sources->replaceSavedFact($source);
    }

    public function removeSource(string $uri): void
    {
        $this->sources->removeSaved($uri);
    }

    public function overlay(SecuritySourceFacts $source): void
    {
        $this->sources->replaceOverlay($source);
    }

    public function removeOverlay(string $uri): void
    {
        $this->sources->removeOverlay($uri);
    }

    /** @return list<SecuritySourceSymbol> */
    public function symbols(SecuritySymbolKind $kind, string $name): array
    {
        $symbols = [];
        foreach ($this->sources->effective() as $source) {
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
        foreach ($this->sources->effective() as $source) {
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
