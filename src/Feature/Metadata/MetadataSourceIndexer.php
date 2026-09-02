<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<MetadataSourceFacts> */
final class MetadataSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(private readonly MetadataSourceIndexRegistry $indexes, private readonly MetadataExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'metadata';
    }

    public function payloadClasses(): array
    {
        return [FormDataClass::class, MetadataSourceFacts::class, MetadataSourceSymbol::class, MetadataSymbolKind::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof MetadataSourceFacts) {
            throw new \UnexpectedValueException('The metadata source facts are invalid.');
        }

        return [
            ...array_values(array_filter($data->symbols, static fn (MetadataSourceSymbol $symbol): bool => $symbol->declaration)),
            ...$data->formDataClasses,
        ];
    }

    protected function factsClass(): string
    {
        return MetadataSourceFacts::class;
    }

    protected function sourceIndex(Project $project): MetadataSourceIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): MetadataSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): MetadataSourceFacts
    {
        return new MetadataSourceFacts($current->uri, [
            ...array_filter($healthy->symbols, static fn (MetadataSourceSymbol $symbol): bool => $symbol->declaration),
            ...array_filter($current->symbols, static fn (MetadataSourceSymbol $symbol): bool => !$symbol->declaration),
        ], $healthy->formDataClasses);
    }
}
