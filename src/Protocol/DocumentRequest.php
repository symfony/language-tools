<?php

namespace Symfony\Lsp\Protocol;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

class DocumentRequest
{
    public function __construct(
        public readonly Document $document,
        public readonly Project $project,
        public readonly SourceDocument $source,
    ) {
    }
}
