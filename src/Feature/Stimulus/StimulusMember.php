<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Range;

final class StimulusMember
{
    public function __construct(
        public readonly string $name,
        public readonly StimulusMemberKind $kind,
        public readonly Range $range,
    ) {
    }
}
