<?php

namespace Symfony\Lsp\Document;

final class Range
{
    public function __construct(
        public readonly Position $start,
        public readonly Position $end,
    ) {
    }
}
