<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Index\SourceFactsInterface;

final class TranslationSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<TranslationDeclaration> $declarations
     * @param list<TranslationReference>   $references
     * @param list<string>                 $globalParameters
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $declarations = [],
        public readonly array $references = [],
        public readonly array $globalParameters = [],
        public readonly bool $dynamicGlobalParameters = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations
            && [] === $this->references
            && [] === $this->globalParameters
            && !$this->dynamicGlobalParameters;
    }
}
