<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;

final class StimulusReference implements RangedSourceSymbolInterface
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
