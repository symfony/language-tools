<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<AssetSourceFacts> */
final class AssetSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(private readonly AssetSourceIndexRegistry $indexes, private readonly AssetExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'assets';
    }

    public function payloadClasses(): array
    {
        return [AssetSourceFacts::class, AssetSourceSymbol::class, AssetSymbolKind::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof AssetSourceFacts) {
            throw new \UnexpectedValueException('The asset source facts are invalid.');
        }

        return array_values(array_filter($data->symbols, static fn (AssetSourceSymbol $symbol): bool => $symbol->declaration));
    }

    protected function factsClass(): string
    {
        return AssetSourceFacts::class;
    }

    protected function sourceIndex(Project $project): AssetSourceIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): AssetSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): AssetSourceFacts
    {
        return new AssetSourceFacts($current->uri, [
            ...array_filter($healthy->symbols, static fn (AssetSourceSymbol $symbol): bool => $symbol->declaration),
            ...array_filter($current->symbols, static fn (AssetSourceSymbol $symbol): bool => !$symbol->declaration),
        ]);
    }
}
