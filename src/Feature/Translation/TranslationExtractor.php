<?php

namespace Symfony\Lsp\Feature\Translation;

final class TranslationExtractor
{
    public function __construct(
        private readonly TranslationCatalogExtractor $catalogs,
        private readonly PhpTranslationReferenceExtractor $phpReferences,
        private readonly TwigTranslationReferenceExtractor $twigReferences,
    ) {
    }

    public function extract(string $uri, string $languageId, string $text): TranslationSourceFacts
    {
        $references = match ($languageId) {
            'php' => $this->phpReferences->extract($uri, $text),
            'twig' => $this->twigReferences->extract($uri, $text),
            default => [],
        };

        return new TranslationSourceFacts($uri, $this->catalogs->extract($uri, $text), $references);
    }
}
