<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Index\SourceFactsInterface;

final class MetadataSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<MetadataSourceSymbol>      $symbols
     * @param list<FormDataClass>             $formDataClasses
     * @param list<FormOptionReference>       $formOptions
     * @param list<ConstraintOptionReference> $constraintOptions
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $symbols,
        public readonly array $formDataClasses = [],
        public readonly array $formOptions = [],
        public readonly array $constraintOptions = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->symbols && [] === $this->formDataClasses && [] === $this->formOptions && [] === $this->constraintOptions;
    }
}
