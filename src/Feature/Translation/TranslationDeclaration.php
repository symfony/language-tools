<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\Range;

final class TranslationDeclaration
{
    public function __construct(
        public readonly string $key,
        public readonly string $domain,
        public readonly string $locale,
        public readonly string $message,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $icu = false,
    ) {
    }

    /** @return list<string> */
    public function placeholders(): array
    {
        return TranslationPlaceholders::extract($this->message, $this->icu);
    }
}
