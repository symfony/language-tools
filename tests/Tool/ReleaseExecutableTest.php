<?php

namespace Symfony\Lsp\Tests\Tool;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Tests\Support\ExecutableRunner;
use Symfony\Lsp\Tests\Support\ProcessResult;
use Symfony\Lsp\Tests\Support\TestWorkspace;

final class ReleaseExecutableTest extends TestCase
{
    public function testLoadsComposerDependencies(): void
    {
        $root = \dirname(__DIR__, 2);
        $result = $this->runProcess(
            [\PHP_BINARY, Path::join($root, 'tools/release'), '0.0.0', '--yes'],
            [...getenv(), 'PATH' => '/missing'],
        );

        self::assertSame(1, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertSame("Required command not found: git.\n", $result->stderr);
    }

    public function testAcceptsCargoFromRustupWithoutPythonOrStandaloneCargo(): void
    {
        $root = \dirname(__DIR__, 2);
        $workspace = new TestWorkspace();
        $workspace->mkdir('bin');
        $bin = $workspace->path('bin');
        foreach (['gh', 'composer', 'npm', 'nvim', 'stylua'] as $command) {
            $workspace->executable('bin/'.$command, "#!/bin/bash\nexit 0\n");
        }
        $workspace->executable('bin/git', <<<'BASH'
            #!/bin/bash
            if [[ "$*" == "branch --show-current" ]]; then echo feature; fi
            BASH);
        $workspace->executable('bin/rustup', <<<'BASH'
            #!/bin/bash
            if [[ "$*" == "which cargo" ]]; then echo /toolchain/bin/cargo; fi
            BASH);

        try {
            $result = $this->runProcess(
                [\PHP_BINARY, Path::join($root, 'tools/release'), '0.0.0', '--yes'],
                [...getenv(), 'PATH' => $bin],
            );

            self::assertSame(1, $result->exitCode);
            self::assertSame('', $result->stdout);
            self::assertSame("Releases must run from main.\n", $result->stderr);
        } finally {
            $workspace->cleanup();
        }
    }

    /** @param list<string> $expectedCalls */
    #[DataProvider('workflowRetryProvider')]
    public function testRetriesFailedWorkflowOnce(int $watchFailures, ?string $expectedError, array $expectedCalls): void
    {
        $root = \dirname(__DIR__, 2);
        $workspace = new TestWorkspace();
        $workspace->mkdir('bin');
        $bin = $workspace->path('bin');
        $calls = $workspace->path('calls');
        $watches = $workspace->path('watches');
        $workspace->executable('bin/gh', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            echo "$*" >> "$GH_CALLS"
            case "$1 $2" in
                "run list") echo 123 ;;
                "run watch")
                    count=0
                    if [[ -f "$GH_WATCHES" ]]; then read -r count < "$GH_WATCHES"; fi
                    count=$((count + 1))
                    echo "$count" > "$GH_WATCHES"
                    if ((count <= GH_WATCH_FAILURES)); then exit 1; fi
                    ;;
                "run view") echo "The workflow failed because the remote service timed out." ;;
            esac
            BASH);
        $php = <<<'PHP'
            $root = $argv[1];
            require $root.'/vendor/autoload.php';
            $processes = new Symfony\Lsp\Tools\ReleaseProcessRunner(new Symfony\Lsp\Tools\InteractiveProcessRunner());
            $command = new Symfony\Lsp\Tools\ReleaseCommand(
                $root,
                new Symfony\Lsp\Tools\ReleaseMetadataUpdater(),
                $processes,
                new Symfony\Lsp\Tools\ReleaseGit($root, $processes),
                new Symfony\Lsp\Tools\ReleaseGitHub($root, $processes),
                new Symfony\Lsp\Tools\NativeReleaseSleeper(),
            );
            $method = (new ReflectionClass($command))->getMethod('waitForWorkflow');
            try {
                $method->invoke($command, 'release.yaml', 'commit');
            } catch (RuntimeException $error) {
                fwrite(STDERR, $error->getMessage()."\n");
            }
            PHP;

        try {
            $environment = getenv();
            $environment['PATH'] = $bin.\PATH_SEPARATOR.($environment['PATH'] ?? '');
            $environment['GH_CALLS'] = $calls;
            $environment['GH_WATCHES'] = $watches;
            $environment['GH_WATCH_FAILURES'] = (string) $watchFailures;
            $result = $this->runProcess([\PHP_BINARY, '-r', $php, $root], $environment);

            self::assertSame(0, $result->exitCode, $result->stderr);
            self::assertStringContainsString('The workflow failed because the remote service timed out.', $result->stdout);
            self::assertStringContainsString("Failed workflow logs:\n", $result->stderr);
            self::assertStringContainsString("Rerunning failed workflow jobs once...\n", $result->stderr);
            if (null === $expectedError) {
                self::assertStringNotContainsString('Workflow release.yaml failed after one automatic rerun.', $result->stderr);
            } else {
                self::assertStringContainsString($expectedError, $result->stderr);
            }
            self::assertSame($expectedCalls, file($calls, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
        } finally {
            $workspace->cleanup();
        }
    }

    public function testDispatchesARegularWorkflowOmittedByPathFiltering(): void
    {
        $root = \dirname(__DIR__, 2);
        $workspace = new TestWorkspace();
        $workspace->mkdir('bin');
        $bin = $workspace->path('bin');
        $calls = $workspace->path('calls');
        $dispatched = $workspace->path('dispatched');
        $workspace->executable('bin/gh', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            echo "$*" >> "$GH_CALLS"
            case "$1 $2" in
                "run list")
                    if [[ -f "$GH_DISPATCHED" ]]; then echo 456; fi
                    ;;
                "workflow run") touch "$GH_DISPATCHED" ;;
            esac
            BASH);
        $php = <<<'PHP'
            $root = $argv[1];
            require $root.'/vendor/autoload.php';
            $processes = new Symfony\Lsp\Tools\ReleaseProcessRunner(new Symfony\Lsp\Tools\InteractiveProcessRunner());
            $sleeper = new class implements Symfony\Lsp\Tools\ReleaseSleeperInterface {
                public function sleep(int $seconds): void {}
            };
            $command = new Symfony\Lsp\Tools\ReleaseCommand(
                $root,
                new Symfony\Lsp\Tools\ReleaseMetadataUpdater(),
                $processes,
                new Symfony\Lsp\Tools\ReleaseGit($root, $processes),
                new Symfony\Lsp\Tools\ReleaseGitHub($root, $processes),
                $sleeper,
            );
            $method = (new ReflectionClass($command))->getMethod('waitForWorkflow');
            $method->invoke($command, 'quality.yaml', 'commit', true);
            PHP;

        try {
            $environment = getenv();
            $environment['PATH'] = $bin.\PATH_SEPARATOR.($environment['PATH'] ?? '');
            $environment['GH_CALLS'] = $calls;
            $environment['GH_DISPATCHED'] = $dispatched;
            $result = $this->runProcess([\PHP_BINARY, '-r', $php, $root], $environment);

            self::assertSame(0, $result->exitCode, $result->stderr);
            self::assertStringContainsString("Dispatching quality.yaml for commit...\n", $result->stdout);
            self::assertSame([
                ...array_fill(0, 6, 'run list --workflow=quality.yaml --commit=commit --limit=1 --json=databaseId --jq=.[0].databaseId // empty'),
                'workflow run quality.yaml --ref main',
                'run list --workflow=quality.yaml --commit=commit --limit=1 --json=databaseId --jq=.[0].databaseId // empty',
                'run watch 456 --exit-status',
            ], file($calls, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
        } finally {
            $workspace->cleanup();
        }
    }

    /** @return iterable<string, array{int, string|null, list<string>}> */
    public static function workflowRetryProvider(): iterable
    {
        yield 'transient failure' => [
            1,
            null,
            [
                'run list --workflow=release.yaml --commit=commit --event=push --limit=1 --json=databaseId --jq=.[0].databaseId // empty',
                'run watch 123 --exit-status',
                'run view 123 --log-failed',
                'run rerun 123 --failed',
                'run watch 123 --exit-status',
            ],
        ];
        yield 'repeated failure' => [
            2,
            'Workflow release.yaml failed after one automatic rerun. Inspect it with "gh run view 123 --web" before resuming the release.',
            [
                'run list --workflow=release.yaml --commit=commit --event=push --limit=1 --json=databaseId --jq=.[0].databaseId // empty',
                'run watch 123 --exit-status',
                'run view 123 --log-failed',
                'run rerun 123 --failed',
                'run watch 123 --exit-status',
                'run view 123 --log-failed',
            ],
        ];
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    private function runProcess(array $command, array $environment): ProcessResult
    {
        return (new ExecutableRunner())->run($command, \dirname(__DIR__, 2), $environment);
    }
}
