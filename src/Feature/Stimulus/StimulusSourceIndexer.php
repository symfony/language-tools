<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class StimulusSourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<StimulusSourceFacts>> */
    private array $facts = [];

    public function __construct(private readonly StimulusSourceIndexRegistry $indexes, private readonly StimulusExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'stimulus';
    }

    public function begin(Project $project): void
    {
        $this->facts[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): StimulusSourceFacts
    {
        return $this->add($project, $this->extract($project, $document));
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!$data instanceof StimulusSourceFacts) {
            throw new \UnexpectedValueException('The cached Stimulus source facts are invalid.');
        }
        $this->add($project, $data);
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->indexes->forProject($project)->replace(...$this->facts[$key]);
        unset($this->facts[$key]);
    }

    public function replace(Project $project, SourceDocument $document): StimulusSourceFacts
    {
        $facts = $this->extract($project, $document);
        $this->indexes->forProject($project)->replaceSource($facts);

        return $facts;
    }

    public function remove(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeSource($uri);
    }

    public function overlay(Project $project, Document $document): void
    {
        $this->indexes->forProject($project)->overlay($this->extractor->extract($project, $document->uri(), $document->languageId(), $document->text()));
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeOverlay($uri);
    }

    private function add(Project $project, StimulusSourceFacts $facts): StimulusSourceFacts
    {
        $this->facts[$project->rootPath()][] = $facts;

        return $facts;
    }

    private function extract(Project $project, SourceDocument $document): StimulusSourceFacts
    {
        return $this->extractor->extract($project, $document->uri(), $document->languageId(), $document->text());
    }
}
