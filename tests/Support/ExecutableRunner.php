<?php

namespace Symfony\Lsp\Tests\Support;

use Amp\ByteStream\ClosedException;
use Amp\Process\Process;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;

final class ExecutableRunner
{
    /**
     * @param list<string>               $command
     * @param array<string, string>|null $environment
     */
    public function run(array $command, ?string $workingDirectory = null, ?array $environment = null, string $input = ''): ProcessResult
    {
        $process = Process::start(
            $command,
            workingDirectory: $workingDirectory,
            environment: $environment ?? getenv(),
            options: ['bypass_shell' => true],
        );
        $futures = [
            'stdout' => async(static fn (): string => buffer($process->getStdout())),
            'stderr' => async(static fn (): string => buffer($process->getStderr())),
            'exitCode' => async(static fn (): int => $process->join()),
        ];
        try {
            if ('' !== $input) {
                $process->getStdin()->write($input);
            }
            $process->getStdin()->end();
        } catch (ClosedException) {
        }

        /** @var array{stdout: string, stderr: string, exitCode: int} $result */
        $result = await($futures);

        return new ProcessResult($result['exitCode'], $result['stdout'], $result['stderr']);
    }
}
