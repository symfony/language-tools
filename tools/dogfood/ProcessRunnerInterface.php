<?php

namespace Symfony\Lsp\Tools\Dogfood;

interface ProcessRunnerInterface
{
    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     *
     * @throws ProcessInterruptedException
     */
    public function run(array $command, ?string $directory = null, float $timeout = 600.0, array $environment = []): ProcessResult;
}
