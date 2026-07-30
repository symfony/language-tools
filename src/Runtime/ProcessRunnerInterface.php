<?php

namespace Symfony\Lsp\Runtime;

interface ProcessRunnerInterface
{
    /**
     * @param non-empty-list<string> $command
     */
    public function run(array $command, string $workingDirectory): ProcessResult;
}
