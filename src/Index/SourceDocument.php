<?php

namespace Symfony\Lsp\Index;

final class SourceDocument
{
    public function __construct(
        private readonly string $uri,
        private readonly string $languageId,
        private readonly string $text,
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function languageId(): string
    {
        return $this->languageId;
    }

    public function text(): string
    {
        return $this->text;
    }
}
