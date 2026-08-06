<?php

namespace Symfony\Lsp\Tools;

final class InteractiveProcessRunner
{
    /** @param list<string> $command */
    public function run(array $command, ?string $workingDirectory = null): int
    {
        $process = proc_open(
            $command,
            [\STDIN, \STDOUT, \STDERR],
            $pipes,
            $workingDirectory,
            options: ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to start interactive command.');
        }

        return proc_close($process);
    }
}
