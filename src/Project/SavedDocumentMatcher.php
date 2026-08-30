<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\Document;

final class SavedDocumentMatcher
{
    public function __construct(private readonly ProjectPathResolver $paths)
    {
    }

    public function matches(Project $project, Document $document): bool
    {
        $relativePath = $this->paths->relative($project, $document->uri);
        if (null === $relativePath || !is_file($path = Path::join($project->rootPath, $relativePath))) {
            return false;
        }
        $hash = @hash_file('sha256', $path);

        return \is_string($hash) && hash_equals($hash, hash('sha256', $document->text));
    }
}
