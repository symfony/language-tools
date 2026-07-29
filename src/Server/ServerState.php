<?php

namespace Symfony\Lsp\Server;

final class ServerState
{
    private bool $initialized = false;
    private bool $shutdown = false;
    private bool $exitRequested = false;

    public function markInitialized(): void
    {
        $this->initialized = true;
    }

    public function markShutdown(): void
    {
        $this->shutdown = true;
    }

    public function requestExit(): void
    {
        $this->exitRequested = true;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function isShutdown(): bool
    {
        return $this->shutdown;
    }

    public function isExitRequested(): bool
    {
        return $this->exitRequested;
    }
}
