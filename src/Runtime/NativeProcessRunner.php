<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;

use function Amp\delay;

final class NativeProcessRunner implements ProcessRunnerInterface
{
    public function __construct(
        private readonly float $timeout = 10.0,
        private readonly int $maximumOutputBytes = 16777216,
    ) {
        if ($timeout <= 0 || $maximumOutputBytes < 1) {
            throw new \InvalidArgumentException('Process limits must be positive.');
        }
    }

    public function run(array $command, string $workingDirectory, ?Cancellation $cancellation = null): ProcessResult
    {
        $cancellation?->throwIfRequested();
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $workingDirectory, null, ['bypass_shell' => true]);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to start the project bridge.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);

        try {
            while (true) {
                $status = proc_get_status($process);
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);

                if (\strlen($stdout) + \strlen($stderr) > $this->maximumOutputBytes) {
                    proc_terminate($process, 9);
                    throw new \RuntimeException('The project bridge exceeded the output limit.');
                }

                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }

                if (microtime(true) - $startedAt >= $this->timeout) {
                    proc_terminate($process, 9);
                    throw new \RuntimeException('The project bridge timed out.');
                }

                delay(0.001, cancellation: $cancellation);
            }

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
        } catch (\Throwable $error) {
            proc_terminate($process, 9);

            throw $error;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closeExitCode = proc_close($process);
        }

        if ($exitCode < 0) {
            $exitCode = $closeExitCode;
        }

        return new ProcessResult($exitCode, $stdout, $stderr);
    }
}
