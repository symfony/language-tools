<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\Range;

final class TranslationDeclaration
{
    public function __construct(
        private readonly string $key,
        private readonly string $domain,
        private readonly string $locale,
        private readonly string $message,
        private readonly string $uri,
        private readonly Range $range,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }

    /** @return list<string> */
    public function placeholders(): array
    {
        return TranslationPlaceholders::extract($this->message);
    }
}
