<?php

namespace Symfony\Lsp\Tools;

use Amp\Process\Process;
use Amp\Process\ProcessException;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\Future\await;
use function Amp\Future\awaitAll;

final class ReleaseCommand
{
    private const EXPECTED_RELEASE_FILES = [
        'CHANGELOG.md',
        'docs/editors/neovim.rst',
        'docs/editors/vscode.rst',
        'docs/index.rst',
        'editor/vscode/package-lock.json',
        'editor/vscode/package.json',
        'lua/symfony_lsp/version.lua',
    ];
    private const REGULAR_WORKFLOWS = [
        'quality.yaml',
        'compatibility.yaml',
        'neovim.yaml',
        'vscode.yaml',
    ];

    public function __construct(
        private string $root,
        private ReleaseMetadataUpdater $metadataUpdater,
        private InteractiveProcessRunner $interactiveProcessRunner,
    ) {
    }

    public function release(string $version): void
    {
        $releaseVersion = new ReleaseVersion($version);
        $version = $releaseVersion->value();
        $this->assertRequirements();
        $this->assertCleanMainBranch();
        $this->run(
            ['git', 'fetch', 'origin', 'refs/heads/main:refs/remotes/origin/main'],
            $this->root,
        );

        $tag = $releaseVersion->tag();
        $remoteTagExists = $this->succeeds(
            ['git', 'ls-remote', '--exit-code', '--tags', 'origin', 'refs/tags/'.$tag],
            $this->root,
        );
        if (!$remoteTagExists) {
            $this->prepareAndPublishTag($releaseVersion, $tag);
        } elseif ($version !== $this->packageVersion()) {
            throw new \RuntimeException(\sprintf('The project is no longer at version %s.', $version));
        }

        $releaseCommit = $this->remoteTagCommit($tag);
        $this->waitForWorkflow('release.yaml', $releaseCommit);
        $this->finishRelease($releaseCommit);

        $url = $this->capture(
            ['gh', 'release', 'view', $tag, '--json', 'url', '--jq', '.url'],
            $this->root,
        );
        fwrite(\STDOUT, \sprintf("Release %s completed: %s\n", $version, $url));
    }

    private function prepareAndPublishTag(ReleaseVersion $releaseVersion, string $tag): void
    {
        $version = $releaseVersion->value();
        $currentVersion = $this->packageVersion();
        $head = $this->gitRevision('HEAD');
        $originMain = $this->gitRevision('refs/remotes/origin/main');

        if ($currentVersion !== $version) {
            if (!$releaseVersion->isGreaterThan($currentVersion)) {
                throw new \RuntimeException(\sprintf('Version %s must be greater than %s.', $version, $currentVersion));
            }
            if ($head !== $originMain) {
                throw new \RuntimeException('main must match origin/main before preparing a release.');
            }
            if ($this->succeeds(['git', 'rev-parse', '--verify', '--quiet', 'refs/tags/'.$tag], $this->root)) {
                throw new \RuntimeException(\sprintf('Local tag %s already exists.', $tag));
            }

            $this->validateLocally();
            $this->metadataUpdater->prepare($this->root, $currentVersion, $version, gmdate('Y-m-d'));
            $this->run(
                ['npm', 'version', $version, '--no-git-tag-version'],
                $this->root.'/editor/vscode',
            );
            $this->assertPreparedMetadata($version);
            $this->assertExpectedReleaseDiff();
            $this->run(['git', 'add', ...self::EXPECTED_RELEASE_FILES], $this->root);
            $this->run(['git', 'commit', '-m', \sprintf('Prepare the %s release', $version)], $this->root);
            $head = $this->gitRevision('HEAD');
        } else {
            $this->assertPreparedMetadata($version);
            if ($head !== $originMain) {
                $subject = $this->capture(['git', 'log', '-1', '--format=%s'], $this->root);
                if (\sprintf('Prepare the %s release', $version) !== $subject) {
                    throw new \RuntimeException(\sprintf('Version %s has unpushed commits after its release preparation.', $version));
                }
            }
        }

        if ($head !== $originMain) {
            if ($this->gitRevision('HEAD^') !== $originMain) {
                throw new \RuntimeException('The release-preparation commit must be directly based on origin/main.');
            }
            $this->run(
                ['git', 'push', 'origin', 'HEAD:refs/heads/main'],
                $this->root,
            );
        }

        $releaseCommit = $this->gitRevision('HEAD');
        $this->waitForRegularWorkflows($releaseCommit);

        if ($this->succeeds(['git', 'rev-parse', '--verify', '--quiet', 'refs/tags/'.$tag], $this->root)) {
            if ($this->gitRevision('refs/tags/'.$tag) !== $releaseCommit) {
                throw new \RuntimeException(\sprintf('Local tag %s points to an unexpected commit.', $tag));
            }
        } else {
            $this->run(['git', 'tag', $tag], $this->root);
        }
        $this->run(
            ['git', 'push', 'origin', \sprintf('refs/tags/%s:refs/tags/%s', $tag, $tag)],
            $this->root,
        );
    }

    private function validateLocally(): void
    {
        $this->run(['composer', 'test'], $this->root);
        $this->run(['composer', 'phpstan'], $this->root);
        $this->run(['composer', 'cs-check'], $this->root);
        $this->run(['npm', 'ci'], $this->root.'/editor/vscode');
        $this->run(['npm', 'run', 'check'], $this->root.'/editor/vscode');
        $stylua = getenv('STYLUA') ?: 'stylua';
        $this->run([$stylua, '--check', 'lsp', 'lua', 'editor/neovim/tests'], $this->root);
        $this->run([$this->root.'/tools/test-neovim'], $this->root);
        $this->run(['composer', 'server:benchmark'], $this->root);
        $this->run(['composer', 'tree-sitter:build-sidecar'], $this->root);
        $this->run(['composer', 'runtime-refresh:benchmark'], $this->root);

        $dogfoodRoot = realpath($this->root.'/../../symfonycorp');
        if (false === $dogfoodRoot) {
            throw new \RuntimeException('The Symfonycorp dogfood root was not found at ../../symfonycorp.');
        }
        $this->run([
            $this->root.'/tools/dogfood-symfonycorp',
            $this->root.'/bin/symfony-lsp',
            $this->root.'/var/build/tree_sitter_cli/symfony-lsp-tree-sitter',
            $dogfoodRoot,
        ], $this->root);
    }

    private function assertPreparedMetadata(string $version): void
    {
        if ($version !== $this->packageVersion()) {
            throw new \RuntimeException('The VS Code package version does not match the release.');
        }
        $lock = json_decode($this->read($this->root.'/editor/vscode/package-lock.json'), true, flags: \JSON_THROW_ON_ERROR);
        $packages = \is_array($lock) ? ($lock['packages'] ?? null) : null;
        $rootPackage = \is_array($packages) ? ($packages[''] ?? null) : null;
        if (!\is_array($lock) || !\is_array($rootPackage) || $version !== ($lock['version'] ?? null) || $version !== ($rootPackage['version'] ?? null)) {
            throw new \RuntimeException('The VS Code lock file version does not match the release.');
        }
        $changelog = $this->read($this->root.'/CHANGELOG.md');
        if (!str_contains($changelog, \sprintf('## %s (', $version)) || str_contains($changelog, '## Unreleased')) {
            throw new \RuntimeException('The changelog is not prepared for the release.');
        }
        if ("return '{$version}'\n" !== $this->read($this->root.'/lua/symfony_lsp/version.lua')) {
            throw new \RuntimeException('The Neovim plugin version does not match the release.');
        }
        foreach (['docs/index.rst', 'docs/editors/vscode.rst', 'docs/editors/neovim.rst'] as $path) {
            if (!str_contains($this->read($this->root.'/'.$path), $version)) {
                throw new \RuntimeException(\sprintf('%s does not reference version %s.', $path, $version));
            }
        }
    }

    private function assertExpectedReleaseDiff(): void
    {
        $changed = preg_split('/\R/', $this->capture(['git', 'diff', '--name-only'], $this->root), flags: \PREG_SPLIT_NO_EMPTY);
        if (false === $changed) {
            throw new \RuntimeException('Unable to inspect the release diff.');
        }
        sort($changed);
        $expected = self::EXPECTED_RELEASE_FILES;
        sort($expected);
        if ($expected !== $changed) {
            throw new \RuntimeException(\sprintf("Unexpected release files changed.\nExpected: %s\nActual: %s", implode(', ', $expected), implode(', ', $changed)));
        }
    }

    private function finishRelease(string $releaseCommit): void
    {
        if ('dev' !== trim($this->read($this->root.'/resources/version'))) {
            throw new \RuntimeException('resources/version must remain dev.');
        }

        $this->run(
            ['git', 'fetch', 'origin', 'refs/heads/main:refs/remotes/origin/main'],
            $this->root,
        );
        $head = $this->gitRevision('HEAD');
        $originMain = $this->gitRevision('refs/remotes/origin/main');
        if ($head !== $originMain) {
            $subject = $this->capture(['git', 'log', '-1', '--format=%s'], $this->root);
            if ('Start development on the next release' !== $subject
                || $this->gitRevision('HEAD^') !== $originMain
                || $originMain !== $releaseCommit
            ) {
                throw new \RuntimeException('main must match origin/main before completing the release.');
            }
            $this->run(
                ['git', 'push', 'origin', 'HEAD:refs/heads/main'],
                $this->root,
            );
            $this->waitForRegularWorkflows($head);

            return;
        }
        $hasUnreleased = str_contains($this->read($this->root.'/CHANGELOG.md'), '## Unreleased');
        if (!$hasUnreleased && $head !== $releaseCommit) {
            throw new \RuntimeException('The release tag is not the current main commit.');
        }
        if (!$this->metadataUpdater->startNextDevelopment($this->root)) {
            if ($head !== $releaseCommit
                && 'Start development on the next release' === $this->capture(['git', 'log', '-1', '--format=%s'], $this->root)
            ) {
                $this->waitForRegularWorkflows($head);
            }

            return;
        }

        $this->run(['git', 'add', 'CHANGELOG.md'], $this->root);
        $this->run(['git', 'commit', '-m', 'Start development on the next release'], $this->root);
        $postReleaseCommit = $this->gitRevision('HEAD');
        if ($this->gitRevision('HEAD^') !== $releaseCommit) {
            throw new \RuntimeException('The post-release commit must immediately follow the release tag.');
        }
        $this->run(
            ['git', 'push', 'origin', 'HEAD:refs/heads/main'],
            $this->root,
        );
        $this->waitForRegularWorkflows($postReleaseCommit);
    }

    private function waitForRegularWorkflows(string $commit): void
    {
        foreach (self::REGULAR_WORKFLOWS as $workflow) {
            $this->waitForWorkflow($workflow, $commit);
        }
    }

    private function waitForWorkflow(string $workflow, string $commit): void
    {
        fwrite(\STDOUT, \sprintf("Waiting for %s on %s...\n", $workflow, $commit));
        $runId = '';
        for ($attempt = 0; $attempt < 120; ++$attempt) {
            $runId = $this->capture([
                'gh',
                'run',
                'list',
                '--workflow='.$workflow,
                '--commit='.$commit,
                '--event=push',
                '--limit=1',
                '--json=databaseId',
                '--jq=.[0].databaseId // empty',
            ], $this->root);
            if ('' !== $runId) {
                break;
            }
            sleep(5);
        }
        if ('' === $runId) {
            throw new \RuntimeException(\sprintf('No %s workflow appeared for %s.', $workflow, $commit));
        }

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            if (0 === $this->runStatus(['gh', 'run', 'watch', $runId, '--exit-status'], $this->root)) {
                return;
            }

            fwrite(\STDERR, "\nFailed workflow logs:\n");
            $this->runStatus(['gh', 'run', 'view', $runId, '--log-failed'], $this->root);

            if (0 === $attempt) {
                fwrite(\STDERR, "\nRerunning failed workflow jobs once...\n");
                $this->run(['gh', 'run', 'rerun', $runId, '--failed'], $this->root);
            }
        }

        throw new \RuntimeException(\sprintf('Workflow %s failed after one automatic rerun. Inspect it with "gh run view %s --web" before resuming the release.', $workflow, $runId));
    }

    private function assertRequirements(): void
    {
        foreach (['git', 'gh', 'composer', 'npm'] as $command) {
            if (!$this->succeeds(['/usr/bin/env', $command, '--version'], $this->root)) {
                throw new \RuntimeException(\sprintf('Required command not found: %s.', $command));
            }
        }
        foreach ([getenv('NVIM') ?: 'nvim', getenv('STYLUA') ?: 'stylua'] as $command) {
            if (!$this->succeeds([$command, '--version'], $this->root)) {
                throw new \RuntimeException(\sprintf('Required command not found: %s.', $command));
            }
        }
    }

    private function assertCleanMainBranch(): void
    {
        if ('main' !== $this->capture(['git', 'branch', '--show-current'], $this->root)) {
            throw new \RuntimeException('Releases must run from main.');
        }
        if ('' !== $this->capture(['git', 'status', '--porcelain=v1', '--untracked-files=no'], $this->root)) {
            throw new \RuntimeException('Tracked files must be clean before releasing.');
        }
    }

    private function packageVersion(): string
    {
        $package = json_decode($this->read($this->root.'/editor/vscode/package.json'), true, flags: \JSON_THROW_ON_ERROR);
        $version = \is_array($package) ? ($package['version'] ?? null) : null;
        if (!\is_string($version)) {
            throw new \RuntimeException('The VS Code package has no version.');
        }
        if ("return '{$version}'\n" !== $this->read($this->root.'/lua/symfony_lsp/version.lua')) {
            throw new \RuntimeException('The Neovim plugin and VS Code package versions differ.');
        }

        return $version;
    }

    private function remoteTagCommit(string $tag): string
    {
        $output = $this->capture(
            ['git', 'ls-remote', '--tags', 'origin', 'refs/tags/'.$tag],
            $this->root,
        );
        $parts = preg_split('/\s+/', $output);
        if (false === $parts || !isset($parts[0]) || !preg_match('/^[0-9a-f]{40}$/', $parts[0])) {
            throw new \RuntimeException(\sprintf('Unable to resolve remote tag %s.', $tag));
        }

        return $parts[0];
    }

    private function gitRevision(string $revision): string
    {
        return $this->capture(['git', 'rev-parse', $revision], $this->root);
    }

    /** @param list<string> $command */
    private function run(array $command, ?string $workingDirectory = null): void
    {
        $status = $this->runStatus($command, $workingDirectory);
        if (0 !== $status) {
            throw new \RuntimeException(\sprintf('Command failed with status %d: %s', $status, $this->formatCommand($command)));
        }
    }

    /** @param list<string> $command */
    private function runStatus(array $command, ?string $workingDirectory = null): int
    {
        fwrite(\STDOUT, '$ '.$this->formatCommand($command)."\n");

        return $this->interactiveProcessRunner->run($command, $workingDirectory);
    }

    /** @param list<string> $command */
    private function capture(array $command, ?string $workingDirectory = null): string
    {
        [$status, $output, $errorOutput] = $this->captureResult($command, $workingDirectory);
        if (0 !== $status) {
            throw new \RuntimeException(\sprintf("Command failed with status %d: %s\n%s", $status, $this->formatCommand($command), trim($errorOutput)));
        }

        return trim($output);
    }

    /** @param list<string> $command */
    private function succeeds(array $command, ?string $workingDirectory = null): bool
    {
        [$status] = $this->captureResult($command, $workingDirectory);

        return 0 === $status;
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    private function captureResult(array $command, ?string $workingDirectory): array
    {
        return $this->execute($command, $workingDirectory);
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string, string}
     */
    private function execute(array $command, ?string $workingDirectory): array
    {
        try {
            $process = Process::start(
                $command,
                workingDirectory: $workingDirectory,
                options: ['bypass_shell' => true],
            );
        } catch (ProcessException $error) {
            throw new \RuntimeException('Unable to start command: '.$this->formatCommand($command), previous: $error);
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

            throw new \RuntimeException('Unable to run command: '.$this->formatCommand($command), previous: $error);
        }

        return [$result['exitCode'], $result['stdout'], $result['stderr']];
    }

    /** @param list<string> $command */
    private function formatCommand(array $command): string
    {
        $formatted = [];
        foreach ($command as $argument) {
            $formatted[] = escapeshellarg($argument);
        }

        return implode(' ', $formatted);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(\sprintf('Unable to read %s.', $path));
        }

        return $contents;
    }
}
