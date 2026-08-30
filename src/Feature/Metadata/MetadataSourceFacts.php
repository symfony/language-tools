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
        public readonly string $uri,
        public readonly array $symbols,
        public readonly array $formDataClasses = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->symbols && [] === $this->formDataClasses;
    }
}
