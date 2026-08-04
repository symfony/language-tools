<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Range;

final class StimulusControllerDeclaration
{
    /** @param list<StimulusMember> $members */
    public function __construct(
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly array $members,
        private readonly bool $lazy,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }

    /** @return list<StimulusMember> */
    public function members(): array
    {
        return $this->members;
    }

    public function isLazy(): bool
    {
        return $this->lazy;
    }
}
