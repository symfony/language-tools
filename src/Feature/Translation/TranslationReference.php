<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\Range;

final class TranslationReference
{
    /** @param list<string>|null $placeholders null when the parameters are dynamic and unknown */
    public function __construct(
        public readonly string $key,
        public readonly string $domain,
        public readonly string $uri,
        public readonly Range $range,
        public readonly ?array $placeholders = null,
    ) {
    }
}
