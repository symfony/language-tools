<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<EventSourceFacts> */
final class EventSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(EventSourceIndexRegistry $indexes, private readonly EventExtractor $extractor)
    {
        parent::__construct($indexes, 'event', EventSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [EventSourceSymbol::class, InvalidEventListenerMethod::class];
    }

    protected function extract(Project $project, SourceDocument $document): EventSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return [
            ...array_filter($facts->symbols, static fn (EventSourceSymbol $symbol): bool => $symbol->declaration),
            ...$facts->listeners,
        ];
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): EventSourceFacts
    {
        return new EventSourceFacts($current->uri, [
            ...array_filter($healthy->symbols, static fn (EventSourceSymbol $symbol): bool => $symbol->declaration),
            ...array_filter($current->symbols, static fn (EventSourceSymbol $symbol): bool => !$symbol->declaration),
        ], $current->invalidListenerMethods, $healthy->listeners);
    }
}
