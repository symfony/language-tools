<?php

namespace Symfony\Lsp\Tests\Tool;

use Amp\Process\Process;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;

final class PublishVscodeTest extends TestCase
{
    private string $directory;
    private string $packages;
    private string $state;

    protected function setUp(): void
    {
        $this->directory = Path::join(sys_get_temp_dir(), 'symfony-lsp-'.bin2hex(random_bytes(8)));
        $this->packages = Path::join($this->directory, 'packages');
        $this->state = Path::join($this->directory, 'state');
        $bin = Path::join($this->directory, 'bin');
        (new Filesystem())->mkdir([$this->packages, $this->state, $bin]);
        $npx = Path::join($bin, 'npx');
        file_put_contents($npx, <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            package="${!#}"
            name="$(basename "$package")"
            count_file="$STATE_DIRECTORY/$name"
            count=0
            if [[ -f "$count_file" ]]; then count="$(< "$count_file")"; fi
            count=$((count + 1))
            echo "$count" > "$count_file"
            echo "$*" >> "$STATE_DIRECTORY/arguments"
            if [[ "$name" = retry.vsix && "$count" -eq 1 ]]; then exit 1; fi
            if [[ "$name" = failure.vsix ]]; then exit 1; fi
            BASH);
        chmod($npx, 0755);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testPublishesPackagesSeparatelyAndRetriesFailures(): void
    {
        touch(Path::join($this->packages, 'immediate.vsix'));
        touch(Path::join($this->packages, 'retry.vsix'));

        $result = $this->runPublisher();

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame('1', trim((string) file_get_contents(Path::join($this->state, 'immediate.vsix'))));
        self::assertSame('2', trim((string) file_get_contents(Path::join($this->state, 'retry.vsix'))));
        $arguments = file(Path::join($this->state, 'arguments'), \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($arguments);
        self::assertCount(3, $arguments);
        foreach ($arguments as $argument) {
            self::assertStringContainsString('vsce publish --azure-credential --skip-duplicate --pre-release --packagePath ', $argument);
        }
    }

    public function testFailsAfterTheConfiguredAttempts(): void
    {
        touch(Path::join($this->packages, 'failure.vsix'));

        $result = $this->runPublisher(false);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('Unable to publish failure.vsix after 3 attempts.', $result['stderr']);
        self::assertSame('3', trim((string) file_get_contents(Path::join($this->state, 'failure.vsix'))));
        self::assertStringNotContainsString('--pre-release', (string) file_get_contents(Path::join($this->state, 'arguments')));
    }

    /** @return array{stdout: string, stderr: string, exitCode: int} */
    private function runPublisher(bool $prerelease = true): array
    {
        $environment = getenv();
        $environment['PATH'] = Path::join($this->directory, 'bin').\PATH_SEPARATOR.($environment['PATH'] ?? '');
        $environment['PRERELEASE'] = $prerelease ? 'true' : 'false';
        $environment['STATE_DIRECTORY'] = $this->state;
        $environment['VSCE_PUBLISH_ATTEMPTS'] = '3';
        $environment['VSCE_PUBLISH_RETRY_DELAY'] = '0';
        $process = Process::start(
            [Path::join(\dirname(__DIR__, 2), 'tools/publish-vscode'), $this->packages],
            workingDirectory: $this->directory,
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
