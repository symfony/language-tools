<?php

namespace Symfony\Lsp\Runtime;

enum RuntimeRefreshMode: int
{
    case Reuse = 0;
    case Warmup = 1;
    case Clear = 2;

    public function combine(self $mode): self
    {
        return $this->value >= $mode->value ? $this : $mode;
    }
}
