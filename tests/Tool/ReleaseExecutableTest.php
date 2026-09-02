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
    public function testRetriesOnlyWhitelistedTransientWorkflowSteps(int $watchFailures, string $failedSteps, string $failedLog, bool $expectedRetry, ?string $expectedError, array $expectedCalls): void
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
            case "$1 $2 $4" in
                "run list --commit=commit") echo '[{"databaseId":123,"headSha":"commit","displayTitle":"Release"}]' ;;
                "run view --json=jobs") echo "$GH_FAILED_STEPS" ;;
                "run view --log-failed") echo "$GH_FAILED_LOG" ;;
            esac
            if [[ "$1 $2" == "run watch" ]]; then
                count=0
                if [[ -f "$GH_WATCHES" ]]; then read -r count < "$GH_WATCHES"; fi
                count=$((count + 1))
                echo "$count" > "$GH_WATCHES"
                if ((count <= GH_WATCH_FAILURES)); then exit 1; fi
            fi
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
                $method->invoke($command, 'packaging.yaml', 'commit');
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
            $environment['GH_FAILED_STEPS'] = $failedSteps;
            $environment['GH_FAILED_LOG'] = $failedLog;
            $result = $this->runProcess([\PHP_BINARY, '-r', $php, $root], $environment);

            self::assertSame(0, $result->exitCode, $result->stderr);
            self::assertStringContainsString($failedLog, $result->stdout);
            self::assertStringContainsString("Failed workflow logs:\n", $result->stderr);
            if ($expectedRetry) {
                self::assertStringContainsString("Rerunning transient workflow jobs once: Download static-php-cli.\n", $result->stderr);
            } else {
                self::assertStringNotContainsString('Rerunning', $result->stderr);
            }
            if (null === $expectedError) {
                self::assertStringNotContainsString('Workflow packaging.yaml failed', $result->stderr);
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
                    if [[ -f "$GH_DISPATCHED" ]]; then echo '[{"databaseId":456,"headSha":"commit","displayTitle":"Quality"}]'; else echo '[]'; fi
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
                ...array_fill(0, 6, 'run list --workflow=quality.yaml --commit=commit --limit=20 --json=databaseId,headSha,displayTitle'),
                'workflow run quality.yaml --ref main',
                'run list --workflow=quality.yaml --commit=commit --limit=20 --json=databaseId,headSha,displayTitle',
                'run watch 456 --exit-status',
            ], file($calls, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
        } finally {
            $workspace->cleanup();
        }
    }

    /** @param list<string> $expectedCalls */
    #[DataProvider('candidateResumeProvider')]
    public function testDispatchesMissingReleaseCandidateAndResumesExistingCandidate(bool $existing, array $expectedCalls): void
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
                    if [[ "$GH_EXISTING" == 1 || -f "$GH_DISPATCHED" ]]; then
                        echo '[{"databaseId":789,"headSha":"releasecommit","displayTitle":"Release candidate v0.19.0"}]'
                    else
                        echo '[]'
                    fi
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
            (new ReflectionClass($command))->getMethod('waitForReleaseCandidate')->invoke($command, 'v0.19.0', 'releasecommit');
            PHP;

        try {
            $environment = getenv();
            $environment['PATH'] = $bin.\PATH_SEPARATOR.($environment['PATH'] ?? '');
            $environment['GH_CALLS'] = $calls;
            $environment['GH_DISPATCHED'] = $dispatched;
            $environment['GH_EXISTING'] = $existing ? '1' : '0';
            $result = $this->runProcess([\PHP_BINARY, '-r', $php, $root], $environment);

            self::assertSame(0, $result->exitCode, $result->stderr);
            self::assertSame($expectedCalls, file($calls, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
        } finally {
            $workspace->cleanup();
        }
    }

    #[DataProvider('candidateMismatchProvider')]
    public function testReleaseCandidateLookupRequiresExactCommitAndVersion(string $runs, string $expectedOutput, string $expectedError): void
    {
        $root = \dirname(__DIR__, 2);
        $workspace = new TestWorkspace();
        $workspace->mkdir('bin');
        $bin = $workspace->path('bin');
        $workspace->executable('bin/gh', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            echo "$GH_RUNS"
            BASH);
        $php = <<<'PHP'
            $root = $argv[1];
            require $root.'/vendor/autoload.php';
            $processes = new Symfony\Lsp\Tools\ReleaseProcessRunner(new Symfony\Lsp\Tools\InteractiveProcessRunner());
            $github = new Symfony\Lsp\Tools\ReleaseGitHub($root, $processes);
            try {
                $runId = $github->workflowRunId('release-candidate.yaml', 'releasecommit', 'workflow_dispatch', 'Release candidate v0.19.0');
                fwrite(STDOUT, ('' === $runId ? 'NONE' : $runId)."\n");
            } catch (RuntimeException $error) {
                fwrite(STDERR, $error->getMessage()."\n");
            }
            PHP;

        try {
            $environment = getenv();
            $environment['PATH'] = $bin.\PATH_SEPARATOR.($environment['PATH'] ?? '');
            $environment['GH_RUNS'] = $runs;
            $result = $this->runProcess([\PHP_BINARY, '-r', $php, $root], $environment);

            self::assertSame(0, $result->exitCode);
            self::assertSame($expectedOutput, $result->stdout);
            self::assertSame($expectedError, $result->stderr);
        } finally {
            $workspace->cleanup();
        }
    }

    public function testPublicDogfoodingIsRequiredBeforeTagging(): void
    {
        $root = \dirname(__DIR__, 2);
        $workspace = new TestWorkspace();
        $workspace->mkdir('bin');
        $bin = $workspace->path('bin');
        $calls = $workspace->path('calls');
        $workspace->executable('bin/gh', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            echo "$*" >> "$GH_CALLS"
            if [[ "$1 $2" == "run list" ]]; then
                echo '[{"databaseId":123,"headSha":"releasecommit","displayTitle":"Release checks"}]'
            fi
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
            (new ReflectionClass($command))->getMethod('waitForPreTagWorkflows')->invoke($command, 'releasecommit');
            PHP;

        try {
            $environment = getenv();
            $environment['PATH'] = $bin.\PATH_SEPARATOR.($environment['PATH'] ?? '');
            $environment['GH_CALLS'] = $calls;
            $result = $this->runProcess([\PHP_BINARY, '-r', $php, $root], $environment);

            self::assertSame(0, $result->exitCode, $result->stderr);
            $workflowCalls = file($calls, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($workflowCalls);
            self::assertContains('run list --workflow=packaging.yaml --commit=releasecommit --limit=20 --json=databaseId,headSha,displayTitle', $workflowCalls);
            self::assertContains('run list --workflow=dogfood.yaml --commit=releasecommit --limit=20 --json=databaseId,headSha,displayTitle', $workflowCalls);
            self::assertSame(7, \count(array_filter($workflowCalls, static fn (string $call): bool => str_starts_with($call, 'run watch '))));
        } finally {
            $workspace->cleanup();
        }
    }

    public function testRefusesReleasePreparationWhenCurrentMainHasFailedWorkflows(): void
    {
        $root = \dirname(__DIR__, 2);
        $workspace = new TestWorkspace();
        $workspace->mkdir('bin');
        $bin = $workspace->path('bin');
        $calls = $workspace->path('calls');
        foreach (['composer', 'npm', 'nvim', 'stylua'] as $command) {
            $workspace->executable('bin/'.$command, \sprintf("#!/bin/bash\nprintf '%%s %%s\\n' '%s' \"\$*\" >> \"\$TOOL_CALLS\"\n", $command));
        }
        $workspace->executable('bin/rustup', <<<'BASH'
            #!/bin/bash
            echo "rustup $*" >> "$TOOL_CALLS"
            BASH);
        $workspace->executable('bin/git', <<<'BASH'
            #!/bin/bash
            set -euo pipefail
            echo "git $*" >> "$TOOL_CALLS"
            case "$*" in
                "branch --show-current") echo main ;;
                "ls-remote --exit-code --tags origin refs/tags/v0.19.0") exit 2 ;;
                "rev-parse HEAD"|"rev-parse refs/remotes/origin/main") echo aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ;;
                "rev-parse --verify --quiet refs/tags/v0.19.0") exit 1 ;;
            esac
            BASH);
        $workspace->executable('bin/gh', <<<'BASH'
            #!/bin/bash
            set -euo pipefail
            echo "gh $*" >> "$TOOL_CALLS"
            if [[ "$1 ${2:-}" == "run list" ]]; then
                echo '[{"workflowName":"PHP quality","status":"completed","conclusion":"failure"}]'
            fi
            BASH);

        try {
            $environment = getenv();
            $environment['PATH'] = $bin;
            $environment['TOOL_CALLS'] = $calls;
            $result = $this->runProcess(
                [\PHP_BINARY, Path::join($root, 'tools/release'), '0.19.0', '--yes'],
                $environment,
            );

            self::assertSame(1, $result->exitCode);
            self::assertSame("Current main has failed workflows: PHP quality.\n", $result->stderr);
            $toolCalls = file($calls, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($toolCalls);
            self::assertContains('gh run list --branch=main --commit=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --limit=100 --json=workflowName,status,conclusion', $toolCalls);
            self::assertNotContains('composer test', $toolCalls);
            self::assertNotContains('npm version 0.19.0 --no-git-tag-version', $toolCalls);
        } finally {
            $workspace->cleanup();
        }
    }

    /** @return iterable<string, array{bool, list<string>}> */
    public static function candidateResumeProvider(): iterable
    {
        $runList = 'run list --workflow=release-candidate.yaml --commit=releasecommit --event=workflow_dispatch --limit=20 --json=databaseId,headSha,displayTitle';

        yield 'dispatch missing candidate' => [
            false,
            [
                $runList,
                'workflow run release-candidate.yaml --ref main --raw-field=version=v0.19.0',
                $runList,
                'run watch 789 --exit-status',
            ],
        ];
        yield 'resume existing candidate' => [
            true,
            [
                $runList,
                'run watch 789 --exit-status',
            ],
        ];
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function candidateMismatchProvider(): iterable
    {
        yield 'different commit' => [
            '[{"databaseId":789,"headSha":"othercommit","displayTitle":"Release candidate v0.19.0"}]',
            '',
            "The release-candidate.yaml workflow run points to an unexpected commit.\n",
        ];
        yield 'different version' => [
            '[{"databaseId":789,"headSha":"releasecommit","displayTitle":"Release candidate dev"}]',
            "NONE\n",
            '',
        ];
        yield 'exact commit and version' => [
            '[{"databaseId":789,"headSha":"releasecommit","displayTitle":"Release candidate v0.19.0"}]',
            "789\n",
            '',
        ];
    }

    /** @return iterable<string, array{int, string, string, bool, string|null, list<string>}> */
    public static function workflowRetryProvider(): iterable
    {
        $runList = 'run list --workflow=packaging.yaml --commit=commit --event=push --limit=20 --json=databaseId,headSha,displayTitle';
        $transientSteps = '{"jobs":[{"steps":[{"name":"Download static-php-cli","conclusion":"failure"}]}]}';
        $failedStepsCall = 'run view 123 --json=jobs';

        yield 'whitelisted transient step' => [
            1,
            $transientSteps,
            'The download service failed.',
            true,
            null,
            [$runList, 'run watch 123 --exit-status', $failedStepsCall, 'run view 123 --log-failed', 'run rerun 123 --failed', 'run watch 123 --exit-status'],
        ];
        yield 'repeated whitelisted transient step' => [
            2,
            $transientSteps,
            'The download service failed.',
            true,
            'Workflow packaging.yaml failed after one automatic rerun. Inspect it with "gh run view 123 --web" before resuming the release.',
            [$runList, 'run watch 123 --exit-status', $failedStepsCall, 'run view 123 --log-failed', 'run rerun 123 --failed', 'run watch 123 --exit-status', $failedStepsCall, 'run view 123 --log-failed'],
        ];
        yield 'mixed transient and deterministic steps' => [
            1,
            '{"jobs":[{"steps":[{"name":"Download static-php-cli","conclusion":"failure"},{"name":"Package and smoke-test","conclusion":"failure"}]}]}',
            'Several steps failed.',
            false,
            'Workflow packaging.yaml failed without an automatic rerun. Inspect it with "gh run view 123 --web" before resuming the release.',
            [$runList, 'run watch 123 --exit-status', $failedStepsCall, 'run view 123 --log-failed'],
        ];
        foreach (['Build the server PHAR', 'Run unit tests', 'Package and smoke-test'] as $step) {
            yield 'deterministic '.$step => [
                1,
                \sprintf('{"jobs":[{"steps":[{"name":"%s","conclusion":"failure"}]}]}', $step),
                $step.' failed.',
                false,
                'Workflow packaging.yaml failed without an automatic rerun. Inspect it with "gh run view 123 --web" before resuming the release.',
                [$runList, 'run watch 123 --exit-status', $failedStepsCall, 'run view 123 --log-failed'],
            ];
        }
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
