<?php

namespace Symfony\Lsp\Index;

final class SourceDocument
{
    public function __construct(
        public readonly string $uri,
        public readonly string $languageId,
        public readonly string $text,
    ) {
    }
}
