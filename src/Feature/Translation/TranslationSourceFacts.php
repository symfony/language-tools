<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Index\SourceFactsInterface;

final class TranslationSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<TranslationDeclaration> $declarations
     * @param list<TranslationReference>   $references
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $declarations = [],
        public readonly array $references = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->references;
    }
}
