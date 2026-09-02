<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Document;

final class SourceDocument
{
    public function __construct(
        public readonly string $uri,
        public readonly string $languageId,
        public readonly string $text,
    ) {
    }

    public static function fromDocument(Document $document): self
    {
        return new self($document->uri, $document->languageId, $document->text);
    }
}
