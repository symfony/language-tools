<?php

namespace Symfony\Lsp\Document;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;

final class ProjectDocumentReader
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectPathResolver $paths,
    ) {
    }

    public function read(Project $project, string $uri): ?ProjectDocument
    {
        if (!$this->paths->isApplicationOwned($project, $uri)) {
            return null;
        }
        if (null !== $document = $this->documents->get($uri)) {
            return new ProjectDocument($document->text, $document->version);
        }
        $relativePath = $this->paths->relative($project, $uri);
        if (null === $relativePath || !is_file($path = Path::join($project->rootPath, $relativePath))) {
            return null;
        }
        $text = @file_get_contents($path);

        return false === $text ? null : new ProjectDocument($text, null);
    }

    /** Dependency-owned files are allowed because the running application reported them. */
    public function readRuntimeFile(string $uri): ?ProjectDocument
    {
        if (null !== $document = $this->documents->get($uri)) {
            return new ProjectDocument($document->text, $document->version);
        }
        $path = $this->paths->path($uri);
        if (null === $path || !is_file($path)) {
            return null;
        }
        $text = @file_get_contents($path);

        return false === $text ? null : new ProjectDocument($text, null);
    }
}
