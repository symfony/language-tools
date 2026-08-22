<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\Range;

final class TranslationDeclaration
{
    // declared with a default so payloads cached before the flag existed
    // still unserialize into a valid object
    private bool $icu = false;

    public function __construct(
        private readonly string $key,
        private readonly string $domain,
        private readonly string $locale,
        private readonly string $message,
        private readonly string $uri,
        private readonly Range $range,
        bool $icu = false,
    ) {
        $this->icu = $icu;
    }

    public function icu(): bool
    {
        return $this->icu;
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
        return TranslationPlaceholders::extract($this->message, $this->icu);
    }
}
