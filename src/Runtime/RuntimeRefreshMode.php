<?php

namespace Symfony\Lsp\Runtime;

enum RuntimeRefreshMode: int
{
    /** Keeps the compiled container and refreshes the planned sections from it. */
    case Preserve = 0;

    /** Reuses the container cache the application already built. */
    case Reuse = 1;

    /** Rebuilds the container before the planned sections are described. */
    case Rebuild = 2;

    public function combine(self $mode): self
    {
        return $this->value >= $mode->value ? $this : $mode;
    }
}
