<?php

namespace Symfony\Lsp\Feature\Translation;

final class TranslationMessage
{
    public function __construct(
        private readonly string $key,
        private readonly string $domain,
        private readonly string $locale,
        private readonly string $message,
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

    /** @return list<string> */
    public function placeholders(): array
    {
        return TranslationPlaceholders::extract($this->message);
    }
}
