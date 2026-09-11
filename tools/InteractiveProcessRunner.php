<?php

namespace Symfony\Lsp\Tools;

final class InteractiveProcessRunner
{
    /** @param list<string> $command */
    public function run(array $command, ?string $workingDirectory = null): int
    {
        $this->appendInheritedStreams();
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

        try {
            return proc_close($process);
        } finally {
            $this->appendInheritedStreams();
        }
    }

    /**
     * A child inherits the write position this process started from, so redirected file output
     * overwrites itself unless both sides are repositioned at the end around every child.
     */
    private function appendInheritedStreams(): void
    {
        foreach ([\STDOUT, \STDERR] as $stream) {
            if (stream_get_meta_data($stream)['seekable']) {
                fseek($stream, 0, \SEEK_END);
            }
        }
    }
}
