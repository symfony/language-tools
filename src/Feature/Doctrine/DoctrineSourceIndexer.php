<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<DoctrineSourceFacts> */
final class DoctrineSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(private readonly DoctrineIndexRegistry $indexes, private readonly DoctrineExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'doctrine_v1';
    }

    public function payloadClasses(): array
    {
        return [DoctrineEntity::class, DoctrineField::class, DoctrineRepository::class, DoctrineSourceFacts::class, DoctrineSourceSymbol::class, DoctrineSymbolKind::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof DoctrineSourceFacts) {
            throw new \UnexpectedValueException('The Doctrine source facts are invalid.');
        }

        $declarations = [];
        foreach ($data->symbols as $symbol) {
            if ($symbol->declaration) {
                $declarations[] = $symbol;
            }
        }

        return [...$data->entities, ...$data->repositories, ...$declarations];
    }

    protected function factsClass(): string
    {
        return DoctrineSourceFacts::class;
    }

    protected function sourceIndex(Project $project): DoctrineIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): DoctrineSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): DoctrineSourceFacts
    {
        return new DoctrineSourceFacts($current->uri, $healthy->entities, $healthy->repositories, [
            ...array_filter($healthy->symbols, static fn (DoctrineSourceSymbol $symbol): bool => $symbol->declaration),
            ...array_filter($current->symbols, static fn (DoctrineSourceSymbol $symbol): bool => !$symbol->declaration),
        ]);
    }
}
