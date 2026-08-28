<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Index\SourceFactsInterface;

final class MetadataSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<MetadataSourceSymbol> $symbols
     * @param list<FormDataClass>        $formDataClasses
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $symbols,
        private readonly array $formDataClasses = [],
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<MetadataSourceSymbol> */
    public function symbols(): array
    {
        return $this->symbols;
    }

    /** @return list<FormDataClass> */
    public function formDataClasses(): array
    {
        return $this->formDataClasses;
    }

    public function isEmpty(): bool
    {
        return [] === $this->symbols && [] === $this->formDataClasses;
    }
}
