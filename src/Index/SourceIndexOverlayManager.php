<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class SourceIndexOverlayManager
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly DocumentStore $documents,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly SourceFileEnumerator $files,
        private readonly SourceIndexProviderPipeline $providers,
        private readonly PhpParseHealthResolver $parseHealth,
        private readonly SourceOverlayHealthRegistry $overlayHealth,
    ) {
    }

    public function updateUri(string $uri, bool $includeExcluded = false, bool $trackParseHealth = true): void
    {
        $document = $this->documents->get($uri);
        $project = $this->projects->forDocumentUri($uri);
        $path = $this->uriToPathConverter->convert($uri);
        if (null === $document || null === $project || null === $path) {
            return;
        }
        if (!$this->files->belongsToProject($project, $path)
            || (!$includeExcluded && $this->files->isExcluded($project, $path))
            || $this->files->gitignoreExcluded($project->rootPath, $path)
        ) {
            $this->providers->removeOverlay($project, $uri);
            $this->overlayHealth->clear($uri);

            return;
        }

        $health = $trackParseHealth ? $this->parseHealth->resolve($project, $document) : SourceParseHealth::Healthy;
        if (!$trackParseHealth) {
            $this->overlayHealth->clear($uri);
        }
        $this->providers->overlay($project, $document, $health);
    }

    public function removeUri(string $uri): void
    {
        $project = $this->projects->forDocumentUri($uri);
        if (null !== $project) {
            $this->providers->removeOverlay($project, $uri);
            $this->overlayHealth->clear($uri);
        }
    }

    public function reapply(Project $project): void
    {
        foreach ($this->documents->all() as $document) {
            if ($this->projects->forDocumentUri($document->uri)?->rootPath === $project->rootPath) {
                $this->updateUri($document->uri);
            }
        }
    }

    public function locateUri(string $uri): ?SourceIndexFileLocation
    {
        $project = $this->projects->forDocumentUri($uri);
        $path = $this->uriToPathConverter->convert($uri);
        if (null === $project || null === $path || !$this->files->belongsToProject($project, $path)) {
            return null;
        }
        $relativePath = $this->files->relativePath($project, $path);
        if (null === $relativePath) {
            return null;
        }

        return new SourceIndexFileLocation($project, $uri, $path, $relativePath);
    }
}
