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
        if ($isolated) {
            array_unshift($command, \PHP_BINARY, __DIR__.'/launch-process.php');
        }
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
            $result = new ProcessResult(-1, $stdout->await(), $stderr->await(), null === $signal);
            if (null !== $signal) {
                throw new ProcessInterruptedException($signal);
            }

            return $result;
        } finally {
            foreach ($signalWatchers as $signalWatcher) {
                EventLoop::cancel($signalWatcher);
            }
        }

        return new ProcessResult($exitCode, $stdout->await(), $stderr->await(), false);
    }

    private function kill(Process $process, bool $isolated): void
    {
        if ($isolated && \function_exists('posix_kill') && @posix_kill(-$process->getPid(), \SIGKILL)) {
            return;
        }

        $process->kill();
    }
}
