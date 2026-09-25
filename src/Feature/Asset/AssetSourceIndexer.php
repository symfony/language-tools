<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<AssetSourceFacts> */
final class AssetSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(AssetSourceIndexRegistry $indexes, private readonly AssetExtractor $extractor)
    {
        parent::__construct($indexes, 'assets', AssetSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [AssetSourceSymbol::class, AssetSymbolKind::class];
    }

    protected function extract(Project $project, SourceDocument $document): AssetSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return array_values(array_filter($facts->symbols, static fn (AssetSourceSymbol $symbol): bool => $symbol->declaration));
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): AssetSourceFacts
    {
        return new AssetSourceFacts($current->uri, [
            ...array_filter($healthy->symbols, static fn (AssetSourceSymbol $symbol): bool => $symbol->declaration),
            ...array_filter($current->symbols, static fn (AssetSourceSymbol $symbol): bool => !$symbol->declaration),
        ]);
    }
}
