<?php

namespace Symfony\Lsp\Document;

final class Document
{
    public function __construct(
        public readonly string $uri,
        public readonly string $languageId,
        public readonly int $version,
        public readonly string $text,
    ) {
    }
}
