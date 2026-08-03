<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class AssetSourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<AssetSourceFacts>> */
    private array $facts = [];

    public function __construct(private readonly AssetSourceIndexRegistry $indexes, private readonly AssetExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'assets';
    }

    public function begin(Project $project): void
    {
        $this->facts[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): AssetSourceFacts
    {
        return $this->add($project, $this->extract($document));
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!$data instanceof AssetSourceFacts) {
            throw new \UnexpectedValueException('The cached asset source facts are invalid.');
        }
        $this->add($project, $data);
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->indexes->forProject($project)->replace(...$this->facts[$key]);
        unset($this->facts[$key]);
    }

    public function replace(Project $project, SourceDocument $document): AssetSourceFacts
    {
        $facts = $this->extract($document);
        $this->indexes->forProject($project)->replaceSource($facts);

        return $facts;
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

    private function add(Project $project, AssetSourceFacts $facts): AssetSourceFacts
    {
        $this->facts[$project->rootPath()][] = $facts;

        return $facts;
    }

    private function extract(SourceDocument $document): AssetSourceFacts
    {
        return $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
    }
}
