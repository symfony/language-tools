<?php

namespace Symfony\Lsp\Runtime;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Process\Process;
use Amp\Process\ProcessException;
use Amp\TimeoutCancellation;

use function Amp\async;
use function Amp\Future\await;
use function Amp\Future\awaitAll;

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

    public function run(array $command, string $workingDirectory, ?Cancellation $cancellation = null, ?float $timeout = null): ProcessResult
    {
        $cancellation?->throwIfRequested();
        $timeoutCancellation = new TimeoutCancellation($timeout ?? $this->timeout);
        $outputLimit = new DeferredCancellation();
        $operationCancellation = new CompositeCancellation(...array_filter([
            $cancellation,
            $timeoutCancellation,
            $outputLimit->getCancellation(),
        ]));

        try {
            $process = Process::start(
                $command,
                workingDirectory: $workingDirectory,
                options: ['bypass_shell' => true],
                cancellation: $operationCancellation,
            );
        } catch (ProcessException $error) {
            throw new \RuntimeException('Unable to start the project bridge.', previous: $error);
        }

        $process->getStdin()->close();
        $outputBytes = 0;
        $futures = [
            'stdout' => async(function () use ($process, $operationCancellation, $outputLimit, &$outputBytes): string {
                return $this->read($process->getStdout(), $operationCancellation, $outputLimit, $outputBytes);
            }),
            'stderr' => async(function () use ($process, $operationCancellation, $outputLimit, &$outputBytes): string {
                return $this->read($process->getStderr(), $operationCancellation, $outputLimit, $outputBytes);
            }),
            'exitCode' => async(static fn (): int => $process->join($operationCancellation)),
        ];

        try {
            /** @var array{stdout: string, stderr: string, exitCode: int} $result */
            $result = await($futures);
        } catch (\Throwable $error) {
            $process->kill();
            awaitAll($futures);

            if ($outputLimit->isCancelled()) {
                throw new \RuntimeException('The project bridge exceeded the output limit.', previous: $error);
            }
            if ($timeoutCancellation->isRequested()) {
                throw new \RuntimeException('The project bridge timed out.', previous: $error);
            }

            throw $error;
        }

        return new ProcessResult($result['exitCode'], $result['stdout'], $result['stderr']);
    }

    private function read(ReadableStream $stream, Cancellation $cancellation, DeferredCancellation $outputLimit, int &$outputBytes): string
    {
        $output = '';
        while (null !== $chunk = $stream->read($cancellation)) {
            $outputBytes += \strlen($chunk);
            if ($outputBytes > $this->maximumOutputBytes) {
                $outputLimit->cancel();
                throw new \RuntimeException('The project bridge exceeded the output limit.');
            }
            $output .= $chunk;
        }

        return $output;
    }
}
