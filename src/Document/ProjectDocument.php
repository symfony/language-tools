<?php

namespace Symfony\Lsp\Document;

final class ProjectDocument
{
    public function __construct(
        public readonly string $text,
        public readonly ?int $version,
    ) {
    }
}
