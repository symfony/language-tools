<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tests\Support\ExecutableRunner;
use Symfony\Lsp\Tests\Support\ProcessResult;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class PublishOpenVsxTest extends TestCase
{
    private TestWorkspace $workspace;
    private string $packages;
    private string $state;

    protected function setUp(): void
    {
        $this->workspace = new TestWorkspace();
        $this->packages = $this->workspace->path('packages');
        $this->state = $this->workspace->path('state');
        $this->workspace->mkdir('packages', 'state', 'bin');
        $this->workspace->executable('bin/npx', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            if [[ "$2" = verify-pat ]]; then
                echo "$*" >> "$STATE_DIRECTORY/arguments"
                exit
            fi
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
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function testPublishesPackagesSeparatelyAndRetriesFailures(): void
    {
        touch(Path::join($this->packages, 'immediate.vsix'));
        touch(Path::join($this->packages, 'retry.vsix'));

        $result = $this->runPublisher();

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame('1', trim((string) file_get_contents(Path::join($this->state, 'immediate.vsix'))));
        self::assertSame('2', trim((string) file_get_contents(Path::join($this->state, 'retry.vsix'))));
        $arguments = file(Path::join($this->state, 'arguments'), \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($arguments);
        self::assertCount(3, $arguments);
        foreach ($arguments as $argument) {
            self::assertStringContainsString('ovsx publish --skip-duplicate ', $argument);
        }
    }

    public function testFailsAfterTheConfiguredAttempts(): void
    {
        touch(Path::join($this->packages, 'failure.vsix'));

        $result = $this->runPublisher();

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Unable to publish failure.vsix after 3 attempts.', $result->stderr);
        self::assertSame('3', trim((string) file_get_contents(Path::join($this->state, 'failure.vsix'))));
    }

    public function testVerifiesMarketplaceAccess(): void
    {
        $result = $this->runCommand(['--verify', 'symfony']);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame("ovsx verify-pat symfony\n", file_get_contents(Path::join($this->state, 'arguments')));
    }

    public function testRequiresAnAccessToken(): void
    {
        $result = $this->runCommand(['--verify', 'symfony'], false);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('OVSX_PAT is required to publish to Open VSX.', $result->stderr);
        self::assertFileDoesNotExist(Path::join($this->state, 'arguments'));
    }

    private function runPublisher(): ProcessResult
    {
        return $this->runCommand([$this->packages]);
    }

    /** @param list<string> $arguments */
    private function runCommand(array $arguments, bool $authenticated = true): ProcessResult
    {
        $environment = getenv();
        $environment['PATH'] = $this->workspace->path('bin').\PATH_SEPARATOR.($environment['PATH'] ?? '');
        $environment['STATE_DIRECTORY'] = $this->state;
        $environment['OVSX_PUBLISH_ATTEMPTS'] = '3';
        $environment['OVSX_PUBLISH_RETRY_DELAY'] = '0';
        $environment['OVSX_PAT'] = $authenticated ? 'token' : '';

        return (new ExecutableRunner())->run(
            [Path::join(\dirname(__DIR__, 2), 'tools/publish-open-vsx'), ...$arguments],
            $this->workspace->rootPath,
            $environment,
        );
    }
}
