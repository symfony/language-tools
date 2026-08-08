<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class TranslationSourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<TranslationSourceFacts>> */
    private array $facts = [];

    public function __construct(private readonly TranslationIndexRegistry $indexes, private readonly TranslationExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'translations';
    }

    public function begin(Project $project): void
    {
        $this->facts[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): TranslationSourceFacts
    {
        return $this->add($project, $this->extract($document));
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!$data instanceof TranslationSourceFacts) {
            throw new \UnexpectedValueException('The cached translation source facts are invalid.');
        }

        $this->add($project, $data);
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->indexes->forProject($project)->replaceSources(...$this->facts[$key]);
        unset($this->facts[$key]);
    }

    public function replace(Project $project, SourceDocument $document): TranslationSourceFacts
    {
        $facts = $this->extract($document);
        $this->indexes->forProject($project)->replaceSource($facts);

        return $facts;
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof TranslationSourceFacts) {
            throw new \UnexpectedValueException('The translation source facts are invalid.');
        }

        return $data->declarations();
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

    private function add(Project $project, TranslationSourceFacts $facts): TranslationSourceFacts
    {
        $this->facts[$project->rootPath()][] = $facts;

        return $facts;
    }

    private function extract(SourceDocument $document): TranslationSourceFacts
    {
        return $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
    }
}
