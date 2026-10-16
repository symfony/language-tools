<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<DependencyInjectionSourceFacts> */
final class DependencyInjectionSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        DependencyInjectionSourceIndexRegistry $indexes,
        private readonly DependencyInjectionDocumentExtractor $extractor,
    ) {
        parent::__construct($indexes, 'dependency_injection', DependencyInjectionSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [
            DependencyInjectionReference::class,
            DependencyInjectionSymbol::class,
            DependencyInjectionSymbolKind::class,
            ParameterDeclaration::class,
            PhpClassDeclaration::class,
            ServiceDeclaration::class,
        ];
    }

    protected function extract(Project $project, SourceDocument $document): ?DependencyInjectionSourceFacts
    {
        return $this->extractor->extractForIndexing($project, $document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return [
            ...$facts->services,
            ...$facts->parameters,
            ...$facts->references,
            ...$facts->classes,
        ];
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): DependencyInjectionSourceFacts
    {
        return new DependencyInjectionSourceFacts(
            $current->uri,
            $healthy->services,
            $healthy->parameters,
            $current->references,
            $healthy->classes,
        );
    }
}
