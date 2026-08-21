<?php

namespace Symfony\Lsp\Document;

use Symfony\Lsp\Project\Project;

final class DocumentContext
{
    public function __construct(
        public readonly Document $document,
        public readonly Project $project,
    ) {
    }
}
