<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class EventSourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<EventSourceFacts>> */
    private array $facts = [];

    public function __construct(private readonly EventSourceIndexRegistry $indexes, private readonly EventExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'events';
    }

    public function begin(Project $project): void
    {
        $this->facts[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): EventSourceFacts
    {
        return $this->add($project, $this->extract($document));
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!$data instanceof EventSourceFacts) {
            throw new \UnexpectedValueException('The cached event source facts are invalid.');
        }

        $this->add($project, $data);
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->indexes->forProject($project)->replace(...$this->facts[$key]);
        unset($this->facts[$key]);
    }

    public function replace(Project $project, SourceDocument $document): EventSourceFacts
    {
        $facts = $this->extract($document);
        $this->indexes->forProject($project)->replaceSource($facts);

        return $facts;
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

    public function remove(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeSource($uri);
    }

    public function overlay(Project $project, Document $document): void
    {
        $this->indexes->forProject($project)->overlay($this->extractor->extract($document->uri(), $document->languageId(), $document->text()));
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeOverlay($uri);
    }

    private function add(Project $project, EventSourceFacts $facts): EventSourceFacts
    {
        $this->facts[$project->rootPath()][] = $facts;

        return $facts;
    }

    private function extract(SourceDocument $document): EventSourceFacts
    {
        return $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
    }
}
