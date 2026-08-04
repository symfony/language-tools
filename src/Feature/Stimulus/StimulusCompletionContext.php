<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Range;

final class StimulusCompletionContext
{
    public function __construct(
        private readonly ?StimulusMemberKind $kind,
        private readonly ?string $controller,
        private readonly string $prefix,
        private readonly Range $range,
    ) {
    }

    public function kind(): ?StimulusMemberKind
    {
        return $this->kind;
    }

    public function controller(): ?string
    {
        return $this->controller;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function range(): Range
    {
        return $this->range;
    }
}
