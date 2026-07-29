<?php

namespace Symfony\Lsp\Document;

final class Range
{
    public function __construct(
        private readonly Position $start,
        private readonly Position $end,
    ) {
    }

    public function start(): Position
    {
        return $this->start;
    }

    public function end(): Position
    {
        return $this->end;
    }
}
