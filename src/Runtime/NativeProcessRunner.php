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
        private readonly float $timeout = 300.0,
        private readonly int $maximumOutputBytes = 67108864,
        private readonly int $maximumErrorOutputBytes = 65536,
    ) {
        if ($timeout <= 0 || !is_finite($timeout) || $maximumOutputBytes < 1 || $maximumErrorOutputBytes < 1) {
            throw new \InvalidArgumentException('Process limits must be positive.');
        }
    }

    public function run(array $command, string $workingDirectory, ?Cancellation $cancellation = null, ?float $timeout = null): ProcessResult
    {
        $cancellation?->throwIfRequested();
        $timeout ??= $this->timeout;
        if ($timeout <= 0 || !is_finite($timeout)) {
            throw new \InvalidArgumentException('Process limits must be positive.');
        }
        $timeoutCancellation = new TimeoutCancellation($timeout);
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
        } catch (\Throwable $error) {
            throw $this->operationFailure($error, $timeoutCancellation, $outputLimit, $timeout);
        }

        $process->getStdin()->close();
        $futures = [
            'stdout' => async(function () use ($process, $operationCancellation, $outputLimit): string {
                return $this->read($process->getStdout(), $operationCancellation, $this->maximumOutputBytes, $outputLimit);
            }),
            'stderr' => async(function () use ($process, $operationCancellation): string {
                return $this->read($process->getStderr(), $operationCancellation, $this->maximumErrorOutputBytes);
            }),
            'exitCode' => async(static fn (): int => $process->join($operationCancellation)),
        ];

        try {
            /** @var array{stdout: string, stderr: string, exitCode: int} $result */
            $result = await($futures);
        } catch (\Throwable $error) {
            $process->kill();
            awaitAll($futures);

            throw $this->operationFailure($error, $timeoutCancellation, $outputLimit, $timeout);
        }

        return new ProcessResult($result['exitCode'], $result['stdout'], $result['stderr']);
    }

    private function operationFailure(\Throwable $error, TimeoutCancellation $timeoutCancellation, DeferredCancellation $outputLimit, float $timeout): \Throwable
    {
        if ($outputLimit->isCancelled()) {
            return new \RuntimeException('The project bridge exceeded the output limit.', previous: $error);
        }
        if ($timeoutCancellation->isRequested()) {
            return new \RuntimeException(\sprintf('The project bridge timed out after %s seconds.', $timeout), previous: $error);
        }

        return $error;
    }

    private function read(ReadableStream $stream, Cancellation $cancellation, int $maximumBytes, ?DeferredCancellation $outputLimit = null): string
    {
        $output = '';
        $outputBytes = 0;
        while (null !== $chunk = $stream->read($cancellation)) {
            $chunkBytes = \strlen($chunk);
            if (null !== $outputLimit && $outputBytes + $chunkBytes > $maximumBytes) {
                $outputLimit->cancel();
                throw new \RuntimeException('The project bridge exceeded the output limit.');
            }

            $remainingBytes = $maximumBytes - $outputBytes;
            if ($remainingBytes > 0) {
                $chunk = substr($chunk, 0, $remainingBytes);
                $output .= $chunk;
                $outputBytes += \strlen($chunk);
            }
        }

        return $output;
    }
}
