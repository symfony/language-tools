<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\ClassNameKey;
use Symfony\Lsp\Index\SourceSymbolTable;

/** @extends AbstractSourceFactsIndex<MetadataSourceFacts> */
final class MetadataSourceIndex extends AbstractSourceFactsIndex
{
    /** @var SourceSymbolTable<MetadataSourceSymbol> */
    private SourceSymbolTable $symbols;

    /** @var array<string, string> */
    private array $formDataClasses = [];

    /** @return list<MetadataSourceSymbol> */
    public function symbols(MetadataSymbolKind $kind, ?string $name = null): array
    {
        $this->derive();

        return $this->symbols->symbols($kind->value, $name);
    }

    /** @return list<string> */
    public function names(MetadataSymbolKind $kind): array
    {
        $this->derive();

        return $this->symbols->names($kind->value);
    }

    public function formDataClass(string $formClass): ?string
    {
        $this->derive();

        return $this->formDataClasses[ClassNameKey::from($formClass)] ?? null;
    }

    protected function build(): void
    {
        $this->symbols = new SourceSymbolTable();
        $this->formDataClasses = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->formDataClasses as $formDataClass) {
                $this->formDataClasses[ClassNameKey::from($formDataClass->formClass)] = $formDataClass->dataClass;
            }
            foreach ($facts->symbols as $symbol) {
                $this->symbols->add($symbol->kind->value, $symbol);
            }
        }
    }
}
