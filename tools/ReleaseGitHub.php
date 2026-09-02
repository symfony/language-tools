<?php

namespace Symfony\Lsp\Tools;

final class ReleaseGitHub
{
    public function __construct(private string $root, private ReleaseProcessRunner $processes)
    {
    }

    public function releaseUrl(string $tag): string
    {
        return $this->processes->capture(['gh', 'release', 'view', $tag, '--json', 'url', '--jq', '.url'], $this->root);
    }

    /** @return list<string> */
    public function currentMainWorkflowFailures(string $commit): array
    {
        $output = $this->processes->capture([
            'gh',
            'run',
            'list',
            '--branch=main',
            '--commit='.$commit,
            '--limit=100',
            '--json=workflowName,status,conclusion',
        ], $this->root);
        $runs = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($runs)) {
            throw new \RuntimeException('Unable to inspect current main workflows.');
        }

        $failures = [];
        foreach ($runs as $run) {
            if (!\is_array($run)
                || !\is_string($run['workflowName'] ?? null)
                || !\is_string($run['status'] ?? null)
                || !\is_string($run['conclusion'] ?? null)
            ) {
                throw new \RuntimeException('Unable to inspect current main workflows.');
            }
            if ('completed' === $run['status'] && !\in_array($run['conclusion'], ['neutral', 'skipped', 'success'], true)) {
                $failures[] = $run['workflowName'];
            }
        }

        return array_values(array_unique($failures));
    }

    public function workflowRunId(string $workflow, string $commit, ?string $event = null, ?string $title = null): string
    {
        $command = ['gh', 'run', 'list', '--workflow='.$workflow, '--commit='.$commit];
        if (null !== $event) {
            $command[] = '--event='.$event;
        }
        $command[] = '--limit=20';
        $command[] = '--json=databaseId,headSha,displayTitle';

        $runs = json_decode($this->processes->capture($command, $this->root), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($runs)) {
            throw new \RuntimeException(\sprintf('Unable to inspect %s workflow runs.', $workflow));
        }
        foreach ($runs as $run) {
            if (!\is_array($run)
                || !\is_int($run['databaseId'] ?? null)
                || !\is_string($run['headSha'] ?? null)
                || !\is_string($run['displayTitle'] ?? null)
            ) {
                throw new \RuntimeException(\sprintf('Unable to inspect %s workflow runs.', $workflow));
            }
            if ($commit !== $run['headSha']) {
                throw new \RuntimeException(\sprintf('The %s workflow run points to an unexpected commit.', $workflow));
            }
            if (null === $title || $title === $run['displayTitle']) {
                return (string) $run['databaseId'];
            }
        }

        return '';
    }

    /** @param array<string, string> $inputs */
    public function dispatchWorkflow(string $workflow, array $inputs = []): void
    {
        $command = ['gh', 'workflow', 'run', $workflow, '--ref', 'main'];
        foreach ($inputs as $name => $value) {
            $command[] = '--raw-field='.$name.'='.$value;
        }
        $this->processes->run($command, $this->root);
    }

    /** @return list<string> */
    public function failedStepNames(string $runId): array
    {
        $output = $this->processes->capture(['gh', 'run', 'view', $runId, '--json=jobs'], $this->root);
        $run = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        $jobs = \is_array($run) ? ($run['jobs'] ?? null) : null;
        if (!\is_array($jobs)) {
            throw new \RuntimeException('Unable to inspect failed workflow steps.');
        }

        $failedSteps = [];
        foreach ($jobs as $job) {
            $steps = \is_array($job) ? ($job['steps'] ?? null) : null;
            if (!\is_array($steps)) {
                throw new \RuntimeException('Unable to inspect failed workflow steps.');
            }
            foreach ($steps as $step) {
                if (!\is_array($step) || !\is_string($step['name'] ?? null) || !\is_string($step['conclusion'] ?? null)) {
                    throw new \RuntimeException('Unable to inspect failed workflow steps.');
                }
                if ('failure' === $step['conclusion']) {
                    $failedSteps[] = $step['name'];
                }
            }
        }

        return array_values(array_unique($failedSteps));
    }

    public function watchRun(string $runId): bool
    {
        return 0 === $this->processes->runStatus(['gh', 'run', 'watch', $runId, '--exit-status'], $this->root);
    }

    public function showFailedLogs(string $runId): void
    {
        $this->processes->runStatus(['gh', 'run', 'view', $runId, '--log-failed'], $this->root);
    }

    public function rerunFailedJobs(string $runId): void
    {
        $this->processes->run(['gh', 'run', 'rerun', $runId, '--failed'], $this->root);
    }
}
