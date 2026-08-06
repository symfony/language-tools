<?php

namespace Symfony\Lsp\Tests\Tool;

use Amp\Process\Process;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;

final class ReleaseExecutableTest extends TestCase
{
    public function testLoadsComposerDependencies(): void
    {
        $root = \dirname(__DIR__, 2);
        $result = $this->runProcess(
            [\PHP_BINARY, Path::join($root, 'tools/release'), '0.0.0', '--yes'],
            [...getenv(), 'PATH' => '/missing'],
        );

        self::assertSame(1, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertSame("Required command not found: git.\n", $result['stderr']);
    }

    public function testReportsWorkflowFailureDetailsAndRecoveryCommand(): void
    {
        $root = \dirname(__DIR__, 2);
        $directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-'.bin2hex(random_bytes(8)));
        $bin = Path::join($directory, 'bin');
        (new Filesystem())->mkdir($bin);
        $calls = Path::join($directory, 'calls');
        $gh = Path::join($bin, 'gh');
        file_put_contents($gh, <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            echo "$*" >> "$GH_CALLS"
            case "$1 $2" in
                "run list") echo 123 ;;
                "run watch") exit 1 ;;
                "run view") echo "The workflow failed because the remote service timed out." ;;
            esac
            BASH);
        chmod($gh, 0755);
        $php = <<<'PHP'
            $root = $argv[1];
            require $root.'/vendor/autoload.php';
            require $root.'/tools/InteractiveProcessRunner.php';
            require $root.'/tools/ReleaseMetadataUpdater.php';
            require $root.'/tools/ReleaseCommand.php';
            $command = new Symfony\Lsp\Tools\ReleaseCommand(
                $root,
                new Symfony\Lsp\Tools\ReleaseMetadataUpdater(),
                new Symfony\Lsp\Tools\InteractiveProcessRunner(),
            );
            $method = (new ReflectionClass($command))->getMethod('waitForWorkflow');
            try {
                $method->invoke($command, 'release.yaml', 'commit');
                exit(2);
            } catch (RuntimeException $error) {
                fwrite(STDERR, $error->getMessage()."\n");
            }
            PHP;

        try {
            $environment = getenv();
            $environment['PATH'] = $bin.\PATH_SEPARATOR.($environment['PATH'] ?? '');
            $environment['GH_CALLS'] = $calls;
            $result = $this->runProcess([\PHP_BINARY, '-r', $php, $root], $environment);

            self::assertSame(0, $result['exitCode'], $result['stderr']);
            self::assertStringContainsString('The workflow failed because the remote service timed out.', $result['stdout']);
            self::assertStringContainsString("Failed workflow logs:\n", $result['stderr']);
            self::assertStringContainsString('Workflow release.yaml failed. Rerun it with "gh run rerun 123 --failed", then resume the release.', $result['stderr']);
            $arguments = file($calls, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($arguments);
            self::assertSame('run watch 123 --exit-status', $arguments[1]);
            self::assertSame('run view 123 --log-failed', $arguments[2]);
        } finally {
            (new Filesystem())->remove($directory);
        }
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runProcess(array $command, array $environment): array
    {
        $root = \dirname(__DIR__, 2);
        $process = Process::start(
            $command,
            workingDirectory: $root,
            environment: $environment,
            options: ['bypass_shell' => true],
        );
        $futures = [
            'stdout' => async(static fn (): string => buffer($process->getStdout())),
            'stderr' => async(static fn (): string => buffer($process->getStderr())),
            'exitCode' => async(static fn (): int => $process->join()),
        ];
        $process->getStdin()->close();

        /** @var array{stdout: string, stderr: string, exitCode: int} $result */
        $result = await($futures);

        return $result;
    }
}
