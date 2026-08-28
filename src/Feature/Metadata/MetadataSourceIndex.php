<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\SourceFactsOverlayOrder;

/** @extends AbstractSourceFactsIndex<MetadataSourceFacts> */
final class MetadataSourceIndex extends AbstractSourceFactsIndex
{
    private bool $indexed = false;

    /** @var array<string, list<MetadataSourceSymbol>> */
    private array $symbols = [];

    /** @var array<string, array<string, list<MetadataSourceSymbol>>> */
    private array $symbolsByName = [];

    /** @var array<string, list<string>> */
    private array $names = [];

    /** @var array<string, string> */
    private array $formDataClasses = [];

    public function __construct()
    {
        parent::__construct(SourceFactsOverlayOrder::PreserveSavedPosition);
    }

    /** @return list<MetadataSourceSymbol> */
    public function symbols(MetadataSymbolKind $kind, ?string $name = null): array
    {
        $this->index();

        return null === $name ? $this->symbols[$kind->value] ?? [] : $this->symbolsByName[$kind->value][$name] ?? [];
    }

    /** @return list<string> */
    public function names(MetadataSymbolKind $kind): array
    {
        $this->index();

        return $this->names[$kind->value] ?? [];
    }

    public function formDataClass(string $formClass): ?string
    {
        $this->index();

        return $this->formDataClasses[strtolower(ltrim($formClass, '\\'))] ?? null;
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
        $this->symbolsByName = [];
        $this->formDataClasses = [];
        $names = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->formDataClasses() as $formDataClass) {
                $this->formDataClasses[strtolower(ltrim($formDataClass->formClass(), '\\'))] = $formDataClass->dataClass();
            }
            foreach ($facts->symbols() as $symbol) {
                $kind = $symbol->kind()->value;
                $name = $symbol->name();
                $this->symbols[$kind][] = $symbol;
                $this->symbolsByName[$kind][$name][] = $symbol;
                $names[$kind]['s'.$name] = $name;
            }
        }

        $this->names = [];
        foreach ($names as $kind => $kindNames) {
            $this->names[$kind] = array_values($kindNames);
            sort($this->names[$kind]);
        }
        $this->indexed = true;
    }
}
