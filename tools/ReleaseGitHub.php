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

    public function workflowRunId(string $workflow, string $commit, ?string $event = null): string
    {
        $command = ['gh', 'run', 'list', '--workflow='.$workflow, '--commit='.$commit];
        if (null !== $event) {
            $command[] = '--event='.$event;
        }
        $command[] = '--limit=1';
        $command[] = '--json=databaseId';
        $command[] = '--jq=.[0].databaseId // empty';

        return $this->processes->capture($command, $this->root);
    }

    public function dispatchWorkflow(string $workflow): void
    {
        $this->processes->run(['gh', 'workflow', 'run', $workflow, '--ref', 'main'], $this->root);
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
