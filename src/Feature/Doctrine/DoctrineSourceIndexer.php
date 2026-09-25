<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<DoctrineSourceFacts> */
final class DoctrineSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(DoctrineIndexRegistry $indexes, private readonly DoctrineExtractor $extractor)
    {
        parent::__construct($indexes, 'doctrine', DoctrineSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [DoctrineEntity::class, DoctrineField::class, DoctrineRepository::class, DoctrineSourceSymbol::class, DoctrineSymbolKind::class];
    }

    protected function extract(Project $project, SourceDocument $document): DoctrineSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        $declarations = [];
        foreach ($facts->symbols as $symbol) {
            if ($symbol->declaration) {
                $declarations[] = $symbol;
            }
        }

        return [...$facts->entities, ...$facts->repositories, ...$declarations];
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): DoctrineSourceFacts
    {
        return new DoctrineSourceFacts($current->uri, $healthy->entities, $healthy->repositories, [
            ...array_filter($healthy->symbols, static fn (DoctrineSourceSymbol $symbol): bool => $symbol->declaration),
            ...array_filter($current->symbols, static fn (DoctrineSourceSymbol $symbol): bool => !$symbol->declaration),
        ]);
    }
}
