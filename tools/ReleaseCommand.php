<?php

namespace Symfony\Lsp\Tools;

final class ReleaseCommand
{
    private const EXPECTED_RELEASE_FILES = [
        'CHANGELOG.md',
        'editor/vscode/package-lock.json',
        'editor/vscode/package.json',
    ];
    private const REGULAR_WORKFLOWS = [
        'quality.yaml',
        'compatibility.yaml',
        'neovim.yaml',
        'packaging.yaml',
        'vscode.yaml',
        'zed.yaml',
    ];
    private const PRE_TAG_WORKFLOWS = [
        ...self::REGULAR_WORKFLOWS,
        'dogfood.yaml',
    ];
    private const TRANSIENT_WORKFLOW_STEPS = [
        'Download PHP sources',
        'Download static-php-cli',
        'Run actions/checkout@v7',
        'Run actions/download-artifact@v8',
        'Run actions/setup-node@v7',
        'Run shivammathur/setup-php@v2',
        'Set up job',
    ];
    private const TRANSIENT_WORKFLOW_CONCLUSIONS = [
        'stale',
        'startup_failure',
        'timed_out',
    ];

    public function __construct(
        private string $root,
        private ReleaseMetadataUpdater $metadataUpdater,
        private ReleaseProcessRunner $processes,
        private ReleaseGit $git,
        private ReleaseGitHub $github,
        private ReleaseSleeperInterface $sleeper,
    ) {
    }

    public function release(string $version): void
    {
        $releaseVersion = new ReleaseVersion($version);
        $version = $releaseVersion->value();
        $this->assertRequirements();
        $this->assertCleanMainBranch();
        $this->git->fetchMain();

        $tag = $releaseVersion->tag();
        $remoteTagExists = $this->git->remoteTagExists($tag);
        if (!$remoteTagExists) {
            $this->prepareAndPublishTag($releaseVersion, $tag);
        } elseif ($version !== $this->packageVersion()) {
            throw new \RuntimeException(\sprintf('The project is no longer at version %s.', $version));
        }

        $releaseCommit = $this->git->remoteTagCommit($tag);
        $this->waitForWorkflow('release.yaml', $releaseCommit);
        $this->finishRelease($releaseCommit);

        $url = $this->github->releaseUrl($tag);
        fwrite(\STDOUT, \sprintf("Release %s completed: %s\n", $version, $url));
    }

    private function prepareAndPublishTag(ReleaseVersion $releaseVersion, string $tag): void
    {
        $version = $releaseVersion->value();
        $currentVersion = $this->packageVersion();
        $head = $this->git->revision('HEAD');
        $originMain = $this->git->revision('refs/remotes/origin/main');

        if ($currentVersion !== $version) {
            if (!$releaseVersion->isGreaterThan($currentVersion)) {
                throw new \RuntimeException(\sprintf('Version %s must be greater than %s.', $version, $currentVersion));
            }
            if ($head !== $originMain) {
                throw new \RuntimeException('main must match origin/main before preparing a release.');
            }
            if ($this->git->localTagExists($tag)) {
                throw new \RuntimeException(\sprintf('Local tag %s already exists.', $tag));
            }

            $failures = $this->github->currentMainWorkflowFailures($originMain);
            if ([] !== $failures) {
                throw new \RuntimeException(\sprintf('Current main has failed workflows: %s.', implode(', ', $failures)));
            }

            $this->validateLocally();
            $this->metadataUpdater->prepare($this->root, $version, gmdate('Y-m-d'));
            $this->processes->run(['npm', 'version', $version, '--no-git-tag-version'], $this->root.'/editor/vscode');
            $this->assertPreparedMetadata($version);
            $this->assertExpectedReleaseDiff();
            $this->git->add(self::EXPECTED_RELEASE_FILES);
            $this->git->commit(\sprintf('Prepare the %s release', $version));
            $head = $this->git->revision('HEAD');
        } else {
            $this->assertPreparedMetadata($version);
            if ($head !== $originMain && \sprintf('Prepare the %s release', $version) !== $this->git->subject()) {
                throw new \RuntimeException(\sprintf('Version %s has unpushed commits after its release preparation.', $version));
            }
        }

        if ($head !== $originMain) {
            if ($this->git->revision('HEAD^') !== $originMain) {
                throw new \RuntimeException('The release-preparation commit must be directly based on origin/main.');
            }
            $this->git->pushMain();
        }

        $releaseCommit = $this->git->revision('HEAD');
        $this->waitForPreTagWorkflows($releaseCommit);
        $this->waitForReleaseCandidate($tag, $releaseCommit);

        if ($this->git->localTagExists($tag)) {
            if ($this->git->revision('refs/tags/'.$tag) !== $releaseCommit) {
                throw new \RuntimeException(\sprintf('Local tag %s points to an unexpected commit.', $tag));
            }
        } else {
            $this->git->tag($tag);
        }
        $this->git->pushTag($tag);
    }

    private function validateLocally(): void
    {
        $this->processes->run(['composer', 'autoload-check'], $this->root);
        $this->processes->run(['composer', 'test'], $this->root);
        $this->processes->run(['composer', 'phpstan'], $this->root);
        $this->processes->run(['composer', 'cs-check'], $this->root);
        $this->processes->run(['npm', 'ci'], $this->root.'/editor/vscode');
        $this->processes->run(['npm', 'run', 'check'], $this->root.'/editor/vscode');
        $stylua = getenv('STYLUA') ?: 'stylua';
        $this->processes->run([$stylua, '--config-path', 'editor/neovim/.stylua.toml', '--check', 'editor/neovim'], $this->root);
        $this->processes->run([$this->root.'/editor/neovim/test'], $this->root);
        $this->processes->run(['rustup', 'target', 'add', 'wasm32-wasip2'], $this->root);
        $this->processes->run([$this->root.'/editor/zed/test'], $this->root);
        $this->processes->run(['composer', 'runtime-fixture:install'], $this->root);
        $this->processes->run(['composer', 'server:benchmark'], $this->root);
        $this->processes->run(['composer', 'runtime-refresh:benchmark'], $this->root);
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
    }

    private function assertExpectedReleaseDiff(): void
    {
        $changed = $this->git->changedFiles();
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

        $this->git->fetchMain();
        $head = $this->git->revision('HEAD');
        $originMain = $this->git->revision('refs/remotes/origin/main');
        if ($head !== $originMain) {
            if ('Start development on the next release' !== $this->git->subject()
                || $this->git->revision('HEAD^') !== $originMain
                || $originMain !== $releaseCommit
            ) {
                throw new \RuntimeException('main must match origin/main before completing the release.');
            }
            $this->git->pushMain();
            $this->waitForRegularWorkflows($head);

            return;
        }
        $hasUnreleased = str_contains($this->read($this->root.'/CHANGELOG.md'), '## Unreleased');
        if (!$hasUnreleased && $head !== $releaseCommit) {
            throw new \RuntimeException('The release tag is not the current main commit.');
        }
        if (!$this->metadataUpdater->startNextDevelopment($this->root)) {
            if ($head !== $releaseCommit && 'Start development on the next release' === $this->git->subject()) {
                $this->waitForRegularWorkflows($head);
            }

            return;
        }

        $this->git->add(['CHANGELOG.md']);
        $this->git->commit('Start development on the next release');
        $postReleaseCommit = $this->git->revision('HEAD');
        if ($this->git->revision('HEAD^') !== $releaseCommit) {
            throw new \RuntimeException('The post-release commit must immediately follow the release tag.');
        }
        $this->git->pushMain();
        $this->waitForRegularWorkflows($postReleaseCommit);
    }

    private function waitForRegularWorkflows(string $commit): void
    {
        foreach (self::REGULAR_WORKFLOWS as $workflow) {
            $this->waitForWorkflow($workflow, $commit, true);
        }
    }

    private function waitForPreTagWorkflows(string $commit): void
    {
        foreach (self::PRE_TAG_WORKFLOWS as $workflow) {
            $this->waitForWorkflow($workflow, $commit, true);
        }
    }

    private function waitForReleaseCandidate(string $version, string $commit): void
    {
        $workflow = 'release-candidate.yaml';
        $title = 'Release candidate '.$version;
        fwrite(\STDOUT, \sprintf("Waiting for %s %s on %s...\n", $workflow, $version, $commit));
        $runId = $this->github->workflowRunId($workflow, $commit, 'workflow_dispatch', $title);
        if ('' === $runId) {
            fwrite(\STDOUT, \sprintf("Dispatching %s %s for %s...\n", $workflow, $version, $commit));
            $this->github->dispatchWorkflow($workflow, ['version' => $version]);
            for ($attempt = 0; $attempt < 120; ++$attempt) {
                $runId = $this->github->workflowRunId($workflow, $commit, 'workflow_dispatch', $title);
                if ('' !== $runId) {
                    break;
                }
                $this->sleeper->sleep(5);
            }
        }
        if ('' === $runId) {
            throw new \RuntimeException(\sprintf('No exact %s %s workflow appeared for %s.', $workflow, $version, $commit));
        }

        $this->waitForWorkflowRun($workflow, $runId);
    }

    private function waitForWorkflow(string $workflow, string $commit, bool $dispatchMissing = false): void
    {
        fwrite(\STDOUT, \sprintf("Waiting for %s on %s...\n", $workflow, $commit));
        $runId = '';
        for ($attempt = 0, $attempts = $dispatchMissing ? 6 : 120; $attempt < $attempts; ++$attempt) {
            $runId = $this->github->workflowRunId($workflow, $commit, $dispatchMissing ? null : 'push');
            if ('' !== $runId) {
                break;
            }
            $this->sleeper->sleep(5);
        }
        if ('' === $runId && $dispatchMissing) {
            fwrite(\STDOUT, \sprintf("Dispatching %s for %s...\n", $workflow, $commit));
            $this->github->dispatchWorkflow($workflow);
            for ($attempt = 0; $attempt < 120; ++$attempt) {
                $runId = $this->github->workflowRunId($workflow, $commit);
                if ('' !== $runId) {
                    break;
                }
                $this->sleeper->sleep(5);
            }
        }
        if ('' === $runId) {
            throw new \RuntimeException(\sprintf('No %s workflow appeared for %s.', $workflow, $commit));
        }

        $this->waitForWorkflowRun($workflow, $runId);
    }

    private function waitForWorkflowRun(string $workflow, string $runId): void
    {
        $reran = false;
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            if ($this->github->watchRun($runId)) {
                return;
            }

            $failedSteps = $this->github->failedStepNames($runId);
            fwrite(\STDERR, "\nFailed workflow logs:\n");
            $this->github->showFailedLogs($runId);

            $transientReason = null;
            if ([] !== $failedSteps && [] === array_diff($failedSteps, self::TRANSIENT_WORKFLOW_STEPS)) {
                $transientReason = implode(', ', $failedSteps);
            } elseif ([] === $failedSteps && \in_array($conclusion = $this->github->workflowConclusion($runId), self::TRANSIENT_WORKFLOW_CONCLUSIONS, true)) {
                $transientReason = $conclusion;
            }

            if (0 === $attempt && null !== $transientReason) {
                fwrite(\STDERR, \sprintf("\nRerunning transient workflow jobs once: %s.\n", $transientReason));
                $this->github->rerunFailedJobs($runId);
                $this->sleeper->sleep(5);
                $reran = true;
                continue;
            }

            $reason = $reran ? 'failed after one automatic rerun' : 'failed without an automatic rerun';
            throw new \RuntimeException(\sprintf('Workflow %s %s. Inspect it with "gh run view %s --web" before resuming the release.', $workflow, $reason, $runId));
        }
    }

    private function assertRequirements(): void
    {
        foreach (['git', 'gh', 'composer', 'npm', 'rustup'] as $command) {
            if (!$this->processes->succeeds(['/usr/bin/env', $command, '--version'], $this->root)) {
                throw new \RuntimeException(\sprintf('Required command not found: %s.', $command));
            }
        }
        if (!$this->processes->succeeds(['rustup', 'which', 'cargo'], $this->root)) {
            throw new \RuntimeException('Required Rustup Cargo toolchain not found.');
        }
        foreach ([getenv('NVIM') ?: 'nvim', getenv('STYLUA') ?: 'stylua'] as $command) {
            if (!$this->processes->succeeds([$command, '--version'], $this->root)) {
                throw new \RuntimeException(\sprintf('Required command not found: %s.', $command));
            }
        }
    }

    private function assertCleanMainBranch(): void
    {
        if ('main' !== $this->git->currentBranch()) {
            throw new \RuntimeException('Releases must run from main.');
        }
        if (!$this->git->isClean()) {
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

        return $version;
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
