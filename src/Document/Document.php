<?php

namespace Symfony\Lsp\Document;

final class Document
{
    public function __construct(
        private readonly string $uri,
        private readonly string $languageId,
        private readonly int $version,
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

    public function version(): int
    {
        return $this->version;
    }

    public function text(): string
    {
        return $this->text;
    }
}
