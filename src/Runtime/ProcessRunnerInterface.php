<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;

interface ProcessRunnerInterface
{
    /**
     * @param non-empty-list<string> $command
     */
    public function run(array $command, string $workingDirectory, ?Cancellation $cancellation = null, ?float $timeout = null): ProcessResult;
}
