<?php

namespace Symfony\Lsp\Runtime;

enum RuntimeRefreshMode: int
{
    case Reuse = 0;
    case Clear = 1;

    public function combine(self $mode): self
    {
        return $this->value >= $mode->value ? $this : $mode;
    }
}
