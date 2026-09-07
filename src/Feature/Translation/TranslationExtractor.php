<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Index\SourceDocument;

final class TranslationExtractor
{
    public function __construct(
        private readonly TranslationCatalogExtractor $catalogs,
        private readonly PhpTranslationReferenceExtractor $phpReferences,
        private readonly TwigTranslationReferenceExtractor $twigReferences,
    ) {
    }

    /**
     * The domain scoping key completion at $offset, or null when the call at
     * the cursor sets a domain that isn't statically known.
     */
    public function completionDomain(SourceDocument $document, int $offset): ?string
    {
        return match ($document->languageId) {
            'php' => $this->phpReferences->completionDomain($document->text, $offset),
            'twig' => $this->twigReferences->completionDomain($document->text, $offset),
            default => 'messages',
        };
    }

    public function extract(SourceDocument $document): TranslationSourceFacts
    {
        $globalParameters = [];
        $dynamicGlobalParameters = false;
        if ('php' === $document->languageId) {
            $php = $this->phpReferences->extract($document->uri, $document->text);
            $references = $php->references;
            $globalParameters = $php->globalParameters;
            $dynamicGlobalParameters = $php->dynamicGlobalParameters;
        } else {
            $references = 'twig' === $document->languageId ? $this->twigReferences->extract($document->uri, $document->text) : [];
        }

        return new TranslationSourceFacts(
            $document->uri,
            $this->catalogs->extract($document->uri, $document->text),
            $references,
            $globalParameters,
            $dynamicGlobalParameters,
        );
    }
}
