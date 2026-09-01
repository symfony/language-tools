<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\UriToPathConverter;

final class AssetExtractor
{
    public function __construct(
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly TwigAssetReferenceExtractor $twigReferences,
        private readonly ImportMapEntrypointExtractor $importMapEntrypoints,
        private readonly AssetCompletionContextResolver $completionContexts,
    ) {
    }

    public function extract(SourceDocument $document): AssetSourceFacts
    {
        $symbols = match ($document->languageId) {
            'twig' => $this->twigReferences->extract($document->uri, $document->text),
            'php' => 'importmap.php' === basename($this->uriToPathConverter->convert($document->uri) ?? '') ? $this->importMapEntrypoints->extract($document->uri, $document->text) : [],
            default => [],
        };

        return new AssetSourceFacts($document->uri, $symbols);
    }

    public function completionContext(string $languageId, string $text, int $offset): ?AssetCompletionContext
    {
        return $this->completionContexts->resolve($languageId, $text, $offset);
    }
}
