<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Amp\CancelledException;
use Amp\Process\Process;
use Amp\TimeoutCancellation;

use function Amp\async;
use function Amp\ByteStream\buffer;

final class NativeProcessRunner implements ProcessRunnerInterface
{
    public function run(array $command, ?string $directory = null, float $timeout = 600.0): ProcessResult
    {
        $environment = [];
        foreach (getenv() as $key => $value) {
            $environment[(string) $key] = $value;
        }
        $process = Process::start($command, $directory, $environment);
        $process->getStdin()->close();
        /** @var \Amp\Future<string> $stdout */
        $stdout = async(static fn (): string => buffer($process->getStdout()));
        /** @var \Amp\Future<string> $stderr */
        $stderr = async(static fn (): string => buffer($process->getStderr()));
        try {
            $exitCode = $process->join(new TimeoutCancellation($timeout));
        } catch (CancelledException) {
            $process->kill();

            return new ProcessResult(-1, $stdout->await(), $stderr->await(), true);
        }

        return new ProcessResult($exitCode, $stdout->await(), $stderr->await(), false);
    }
}
