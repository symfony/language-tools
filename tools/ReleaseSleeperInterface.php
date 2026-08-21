<?php

namespace Symfony\Lsp\Tools;

interface ReleaseSleeperInterface
{
    public function sleep(int $seconds): void;
}
