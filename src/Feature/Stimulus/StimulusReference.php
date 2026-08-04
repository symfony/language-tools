<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Range;

final class StimulusReference
{
    public function __construct(
        private readonly string $controller,
        private readonly ?StimulusMemberKind $kind,
        private readonly ?string $member,
        private readonly string $uri,
        private readonly Range $range,
    ) {
    }

    public function controller(): string
    {
        return $this->controller;
    }

    public function kind(): ?StimulusMemberKind
    {
        return $this->kind;
    }

    public function member(): ?string
    {
        return $this->member;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }
}
