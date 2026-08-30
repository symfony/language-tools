<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Range;

final class StimulusControllerDeclaration
{
    /** @param list<StimulusMember> $members */
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly array $members,
        public readonly bool $lazy,
    ) {
    }
}
