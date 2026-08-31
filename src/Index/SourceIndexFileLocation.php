<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;

final class SourceIndexFileLocation
{
    public function __construct(
        public readonly Project $project,
        public readonly string $uri,
        public readonly string $path,
        public readonly string $relativePath,
    ) {
    }
}
