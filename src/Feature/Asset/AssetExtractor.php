<?php

namespace Symfony\Lsp\Feature\Asset;

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

    public function extract(string $uri, string $languageId, string $text): AssetSourceFacts
    {
        $symbols = match ($languageId) {
            'twig' => $this->twigReferences->extract($uri, $text),
            'php' => 'importmap.php' === basename($this->uriToPathConverter->convert($uri) ?? '') ? $this->importMapEntrypoints->extract($uri, $text) : [],
            default => [],
        };

        return new AssetSourceFacts($uri, $symbols);
    }

    public function completionContext(string $languageId, string $text, int $offset): ?AssetCompletionContext
    {
        return $this->completionContexts->resolve($languageId, $text, $offset);
    }
}
