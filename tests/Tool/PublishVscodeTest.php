<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tests\Support\ExecutableRunner;
use Symfony\Lsp\Tests\Support\ProcessResult;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class PublishVscodeTest extends TestCase
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
            self::assertStringContainsString('vsce publish --azure-credential --skip-duplicate --pre-release --packagePath ', $argument);
        }
    }

    public function testFailsAfterTheConfiguredAttempts(): void
    {
        touch(Path::join($this->packages, 'failure.vsix'));

        $result = $this->runPublisher(false);

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Unable to publish failure.vsix after 3 attempts.', $result->stderr);
        self::assertSame('3', trim((string) file_get_contents(Path::join($this->state, 'failure.vsix'))));
        self::assertStringNotContainsString('--pre-release', (string) file_get_contents(Path::join($this->state, 'arguments')));
    }

    private function runPublisher(bool $prerelease = true): ProcessResult
    {
        $environment = getenv();
        $environment['PATH'] = $this->workspace->path('bin').\PATH_SEPARATOR.($environment['PATH'] ?? '');
        $environment['PRERELEASE'] = $prerelease ? 'true' : 'false';
        $environment['STATE_DIRECTORY'] = $this->state;
        $environment['VSCE_PUBLISH_ATTEMPTS'] = '3';
        $environment['VSCE_PUBLISH_RETRY_DELAY'] = '0';

        return (new ExecutableRunner())->run(
            [Path::join(\dirname(__DIR__, 2), 'tools/publish-vscode'), $this->packages],
            $this->workspace->rootPath,
            $environment,
        );
    }
}
