<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Range;

final class StimulusReference
{
    public function __construct(
        public readonly string $controller,
        public readonly ?StimulusMemberKind $kind,
        public readonly ?string $member,
        public readonly string $uri,
        public readonly Range $range,
    ) {
    }
}
