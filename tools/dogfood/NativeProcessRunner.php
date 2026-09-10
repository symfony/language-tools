<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\ByteStream\buffer;

final class NativeProcessRunner implements ProcessRunnerInterface
{
    public function run(array $command, ?string $directory = null, float $timeout = 600.0, array $environment = []): ProcessResult
    {
        $inheritedEnvironment = [];
        foreach (getenv() as $key => $value) {
            $inheritedEnvironment[(string) $key] = $value;
        }
        $isolated = 'Windows' !== \PHP_OS_FAMILY;
        $usageFile = null;
        if ($isolated) {
            array_unshift($command, \PHP_BINARY, __DIR__.'/launch-process.php');
            $usageFile = tempnam(sys_get_temp_dir(), 'symfony-lsp-dogfood-usage-');
            if (false !== $usageFile) {
                $environment['SYMFONY_LSP_DOGFOOD_USAGE_FILE'] = $usageFile;
            }
        }
        $startedAt = hrtime(true);
        $process = Process::start($command, $directory, array_replace($inheritedEnvironment, $environment));
        $process->getStdin()->close();
        /** @var \Amp\Future<string> $stdout */
        $stdout = async(static fn (): string => buffer($process->getStdout()));
        /** @var \Amp\Future<string> $stderr */
        $stderr = async(static fn (): string => buffer($process->getStderr()));

        $signal = null;
        $signalCancellation = new DeferredCancellation();
        $signalWatchers = [];
        if ($isolated) {
            foreach ([\SIGHUP, \SIGINT, \SIGQUIT, \SIGTERM] as $watchedSignal) {
                $signalWatchers[] = EventLoop::onSignal($watchedSignal, static function () use (&$signal, $signalCancellation, $watchedSignal): void {
                    $signal = $watchedSignal;
                    $signalCancellation->cancel();
                });
            }
        }

        try {
            $exitCode = $process->join(new CompositeCancellation(
                new TimeoutCancellation($timeout),
                $signalCancellation->getCancellation(),
            ));
        } catch (CancelledException) {
            $this->kill($process, $isolated);
            $result = new ProcessResult(-1, $stdout->await(), $stderr->await(), null === $signal, $this->elapsedMilliseconds($startedAt));
            if (null !== $signal) {
                throw new ProcessInterruptedException($signal);
            }

            return $result;
        } finally {
            foreach ($signalWatchers as $signalWatcher) {
                EventLoop::cancel($signalWatcher);
            }
            if (false !== $usageFile && null !== $usageFile) {
                $cpuMilliseconds = $this->cpuMilliseconds($usageFile);
                @unlink($usageFile);
            }
        }

        return new ProcessResult($exitCode, $stdout->await(), $stderr->await(), false, $this->elapsedMilliseconds($startedAt), $cpuMilliseconds ?? null);
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 1);
    }

    private function cpuMilliseconds(string $usageFile): ?float
    {
        $contents = @file_get_contents($usageFile);
        if (!\is_string($contents) || '' === $contents) {
            return null;
        }
        $usage = json_decode($contents, true);
        $cpuMilliseconds = \is_array($usage) ? ($usage['cpuMilliseconds'] ?? null) : null;

        return \is_int($cpuMilliseconds) || \is_float($cpuMilliseconds) ? (float) $cpuMilliseconds : null;
    }

    private function kill(Process $process, bool $isolated): void
    {
        if ($isolated && \function_exists('posix_kill') && @posix_kill(-$process->getPid(), \SIGKILL)) {
            return;
        }

        $process->kill();
    }
}
