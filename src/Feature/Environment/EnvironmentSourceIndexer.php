<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<EnvironmentSourceFacts> */
final class EnvironmentSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(EnvironmentIndexRegistry $indexes, private readonly EnvironmentExtractor $extractor)
    {
        parent::__construct($indexes, 'environment', EnvironmentSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [EnvironmentDeclaration::class, EnvironmentReference::class, MalformedEnvironmentExpression::class];
    }

    protected function extract(Project $project, SourceDocument $document): EnvironmentSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return [
            ...$facts->declarations,
            ...$facts->references,
        ];
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): EnvironmentSourceFacts
    {
        return $current;
    }
}
