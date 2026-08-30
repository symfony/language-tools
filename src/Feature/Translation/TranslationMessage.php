<?php

namespace Symfony\Lsp\Feature\Translation;

final class TranslationMessage
{
    public function __construct(
        public readonly string $key,
        public readonly string $domain,
        public readonly string $locale,
        public readonly string $message,
        public readonly bool $icu = false,
    ) {
    }

    /** @return list<string> */
    public function placeholders(): array
    {
        return TranslationPlaceholders::extract($this->message, $this->icu);
    }
}
