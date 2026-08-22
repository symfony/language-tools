<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\Range;

final class TranslationReference
{
    /** @param list<string>|null $placeholders null when the parameters are dynamic and unknown */
    public function __construct(
        private readonly string $key,
        private readonly string $domain,
        private readonly string $uri,
        private readonly Range $range,
        private readonly ?array $placeholders = null,
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

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }

    /** @return list<string>|null */
    public function placeholders(): ?array
    {
        return $this->placeholders;
    }
}
