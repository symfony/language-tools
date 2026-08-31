<?php

namespace Symfony\Lsp\Feature\Translation;

final class PhpTranslationFacts
{
    /**
     * @param list<TranslationReference> $references
     * @param list<string>               $globalParameters
     */
    public function __construct(
        public readonly array $references,
        public readonly array $globalParameters,
        public readonly bool $dynamicGlobalParameters,
    ) {
    }
}
