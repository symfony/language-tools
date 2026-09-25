<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<ConsoleSourceFacts> */
final class ConsoleSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        ConsoleSourceIndexRegistry $indexes,
        private readonly ConsoleExtractor $extractor,
    ) {
        parent::__construct($indexes, 'console', ConsoleSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [ConsoleCommandDeclaration::class, ConsoleInputReference::class, ConsoleInputKind::class];
    }

    protected function extract(Project $project, SourceDocument $document): ConsoleSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return $facts->declarations;
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): ConsoleSourceFacts
    {
        return new ConsoleSourceFacts($current->uri, $healthy->declarations, $current->references);
    }
}
