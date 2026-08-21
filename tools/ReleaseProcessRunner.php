<?php

namespace Symfony\Lsp\Tools;

use Amp\Process\Process;
use Amp\Process\ProcessException;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;
use function Amp\Future\awaitAll;

final class ReleaseProcessRunner
{
    public function __construct(private InteractiveProcessRunner $interactive)
    {
    }

    /** @param list<string> $command */
    public function run(array $command, ?string $workingDirectory = null): void
    {
        $status = $this->runStatus($command, $workingDirectory);
        if (0 !== $status) {
            throw new \RuntimeException(\sprintf('Command failed with status %d: %s', $status, $this->format($command)));
        }
    }

    /** @param list<string> $command */
    public function runStatus(array $command, ?string $workingDirectory = null): int
    {
        fwrite(\STDOUT, '$ '.$this->format($command)."\n");

        return $this->interactive->run($command, $workingDirectory);
    }

    /** @param list<string> $command */
    public function capture(array $command, ?string $workingDirectory = null): string
    {
        [$status, $output, $errorOutput] = $this->execute($command, $workingDirectory);
        if (0 !== $status) {
            throw new \RuntimeException(\sprintf("Command failed with status %d: %s\n%s", $status, $this->format($command), trim($errorOutput)));
        }

        return trim($output);
    }

    /** @param list<string> $command */
    public function succeeds(array $command, ?string $workingDirectory = null): bool
    {
        [$status] = $this->execute($command, $workingDirectory);

        return 0 === $status;
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    private function execute(array $command, ?string $workingDirectory): array
    {
        try {
            $process = Process::start($command, workingDirectory: $workingDirectory, options: ['bypass_shell' => true]);
        } catch (ProcessException $error) {
            throw new \RuntimeException('Unable to start command: '.$this->format($command), previous: $error);
        }

        $process->getStdin()->close();
        $futures = [
            'stdout' => async(static fn (): string => buffer($process->getStdout())),
            'stderr' => async(static fn (): string => buffer($process->getStderr())),
            'exitCode' => async(static fn (): int => $process->join()),
        ];

        try {
            /** @var array{stdout: string, stderr: string, exitCode: int} $result */
            $result = await($futures);
        } catch (\Throwable $error) {
            $process->kill();
            awaitAll($futures);

            throw new \RuntimeException('Unable to run command: '.$this->format($command), previous: $error);
        }

        return [$result['exitCode'], $result['stdout'], $result['stderr']];
    }

    /** @param list<string> $command */
    private function format(array $command): string
    {
        $formatted = [];
        foreach ($command as $argument) {
            $formatted[] = escapeshellarg($argument);
        }

        return implode(' ', $formatted);
    }
}
