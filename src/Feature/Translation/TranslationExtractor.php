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
        $globalParameters = [];
        $dynamicGlobalParameters = false;
        if ('php' === $languageId) {
            $php = $this->phpReferences->extract($uri, $text);
            $references = $php->references;
            $globalParameters = $php->globalParameters;
            $dynamicGlobalParameters = $php->dynamicGlobalParameters;
        } else {
            $references = 'twig' === $languageId ? $this->twigReferences->extract($uri, $text) : [];
        }

        return new TranslationSourceFacts(
            $uri,
            $this->catalogs->extract($uri, $text),
            $references,
            $globalParameters,
            $dynamicGlobalParameters,
        );
    }
}
