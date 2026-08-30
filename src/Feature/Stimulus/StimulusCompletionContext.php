<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Range;

final class StimulusCompletionContext
{
    public function __construct(
        public readonly ?StimulusMemberKind $kind,
        public readonly ?string $controller,
        public readonly string $prefix,
        public readonly Range $range,
    ) {
    }
}
