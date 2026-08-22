<?php

namespace Symfony\Lsp\Tools\Dogfood;

interface ProcessRunnerInterface
{
    /**
     * @param list<string> $command
     */
    public function run(array $command, ?string $directory = null, float $timeout = 600.0): ProcessResult;
}
