<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<EventSourceFacts> */
final class EventSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(private readonly EventSourceIndexRegistry $indexes, private readonly EventExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'events';
    }

    public function payloadClasses(): array
    {
        return [EventSourceFacts::class, EventSourceSymbol::class, InvalidEventListenerMethod::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof EventSourceFacts) {
            throw new \UnexpectedValueException('The event source facts are invalid.');
        }

        return [
            ...array_filter($data->symbols(), static fn (EventSourceSymbol $symbol): bool => $symbol->isDeclaration()),
            ...$data->listeners(),
        ];
    }

    protected function factsClass(): string
    {
        return EventSourceFacts::class;
    }

    protected function sourceIndex(Project $project): EventSourceIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): EventSourceFacts
    {
        return $this->extractor->extract($document->uri, $document->languageId, $document->text);
    }
}
