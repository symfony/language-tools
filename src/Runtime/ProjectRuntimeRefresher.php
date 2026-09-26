<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Feature\Configuration\ConfigurationValidationRegistry;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceFileChange;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\WorkspaceTrust;

final class ProjectRuntimeRefresher
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly ProjectPathResolver $pathResolver,
        private readonly WorkspaceTrust $workspaceTrust,
        private readonly RuntimeRefreshSchedulerInterface $refreshScheduler,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly RuntimeConfiguration $configuration,
        private readonly RuntimeRefreshPlanner $planner,
        private readonly ConfigurationValidationRegistry $configurationValidations,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function refreshAfterSave(array $params, SourceFileChange $sourceFileChange): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $this->refreshUri($textDocument['uri'], $sourceFileChange);
    }

    /** @param list<string> $initializedProjects */
    public function refreshAfterRediscovery(string $uri, array $initializedProjects = []): void
    {
        $project = $this->projects->forDocumentUri($uri);
        if (null === $project || \in_array($project->rootPath, $initializedProjects, true)) {
            return;
        }

        $this->refreshProject($project, $uri, SourceFileChange::untracked());
    }

    public function refreshUri(string $uri, SourceFileChange $sourceFileChange): void
    {
        $project = $this->projects->forDocumentUri($uri);
        if (null !== $project) {
            $this->refreshProject($project, $uri, $sourceFileChange);
        }
    }

    private function refreshProject(Project $project, string $uri, SourceFileChange $sourceFileChange): void
    {
        $path = $this->pathResolver->relative($project, $uri);
        if (!$this->configuration->runtimeIndexing($project)
            || TrustStatus::Trusted !== $this->workspaceTrust->status($project)
            || null === $path
            || !$this->planner->requiresRefresh($path, $sourceFileChange)
        ) {
            return;
        }

        $this->configurationValidations->pending($project);
        $this->statuses->runtimeStale($project);
        $this->refreshScheduler->schedule($project, $this->planner->plan($path, $sourceFileChange));
    }
}
