<?php

namespace Symfony\Lsp\Feature\Translation;

final class TranslationSourceFacts
{
    /**
     * @param list<TranslationDeclaration> $declarations
     * @param list<TranslationReference>   $references
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $declarations = [],
        private readonly array $references = [],
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<TranslationDeclaration> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /** @return list<TranslationReference> */
    public function references(): array
    {
        return $this->references;
    }
}
