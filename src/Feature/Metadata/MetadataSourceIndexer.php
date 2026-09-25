<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<MetadataSourceFacts> */
final class MetadataSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(MetadataSourceIndexRegistry $indexes, private readonly MetadataExtractor $extractor)
    {
        parent::__construct($indexes, 'metadata', MetadataSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [ConstraintOptionReference::class, FormDataClass::class, FormOptionReference::class, MetadataSourceSymbol::class, MetadataSymbolKind::class];
    }

    protected function extract(Project $project, SourceDocument $document): MetadataSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return [
            ...array_values(array_filter($facts->symbols, static fn (MetadataSourceSymbol $symbol): bool => $symbol->declaration)),
            ...$facts->formDataClasses,
        ];
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): MetadataSourceFacts
    {
        return new MetadataSourceFacts($current->uri, [
            ...array_filter($healthy->symbols, static fn (MetadataSourceSymbol $symbol): bool => $symbol->declaration),
            ...array_filter($current->symbols, static fn (MetadataSourceSymbol $symbol): bool => !$symbol->declaration),
        ], $healthy->formDataClasses, $current->formOptions, $current->constraintOptions);
    }
}
