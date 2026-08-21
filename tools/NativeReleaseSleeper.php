<?php

namespace Symfony\Lsp\Tools;

final class NativeReleaseSleeper implements ReleaseSleeperInterface
{
    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
