<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Range;

final class StimulusMember
{
    public function __construct(
        private readonly string $name,
        private readonly StimulusMemberKind $kind,
        private readonly Range $range,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): StimulusMemberKind
    {
        return $this->kind;
    }

    public function range(): Range
    {
        return $this->range;
    }
}
