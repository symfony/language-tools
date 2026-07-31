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

    public function begin(Project $project): void
    {
        $this->facts[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): void
    {
        $this->facts[$project->rootPath()][] = $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->indexes->forProject($project)->replaceSources(...$this->facts[$key]);
        unset($this->facts[$key]);
    }

    public function overlay(Project $project, Document $document): void
    {
        $this->indexes->forProject($project)->overlay($this->extractor->extract($document->uri(), $document->languageId(), $document->text()));
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeOverlay($uri);
    }
}
