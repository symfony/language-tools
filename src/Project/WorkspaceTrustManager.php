<?php

namespace Symfony\Lsp\Project;

use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;

final class WorkspaceTrustManager implements ProjectStateInterface
{
    /** @var array<string, string> */
    private array $runtimeStarted = [];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly WorkspaceTrust $workspaceTrust,
        private readonly RuntimeInitializerInterface $runtimeInitializer,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly RuntimeConfiguration $configuration,
        private readonly ProjectRegistry $projects,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     * @param list<Project>           $projects
     */
    public function applyInitializationOptions(array $params, array $projects): void
    {
        $initializationOptions = $params['initializationOptions'] ?? null;
        if (!\is_array($initializationOptions)
            || !\is_bool($initializationOptions['workspaceTrust'] ?? null)
        ) {
            return;
        }

        $status = $initializationOptions['workspaceTrust']
            ? TrustStatus::Trusted
            : TrustStatus::Untrusted;

        foreach ($projects as $project) {
            $this->workspaceTrust->set($project, $status);
        }
    }

    /** @param list<Project> $projects */
    public function requestUnknownDecisions(array $projects): void
    {
        foreach ($projects as $project) {
            $status = $this->workspaceTrust->status($project);
            if (TrustStatus::Trusted === $status) {
                $this->startRuntime($project);
                continue;
            }
            if (TrustStatus::Untrusted === $status) {
                continue;
            }

            $response = $this->client->request('window/showMessageRequest', [
                'type' => 2,
                'message' => \sprintf(
                    'Symfony Language Tools must execute application code to index runtime metadata for "%s".',
                    $project->rootPath,
                ),
                'actions' => [
                    ['title' => 'Trust and enable runtime indexing'],
                    ['title' => 'Keep static-only mode'],
                ],
            ]);

            if (!$this->projects->contains($project)) {
                // the project was removed while the client decided
                continue;
            }
            $trusted = \is_array($response)
                && 'Trust and enable runtime indexing' === ($response['title'] ?? null);
            $status = $trusted ? TrustStatus::Trusted : TrustStatus::Untrusted;
            $this->workspaceTrust->set($project, $status);
            if (TrustStatus::Trusted === $status) {
                $this->startRuntime($project);
            }
        }
    }

    public function invalidateRuntime(Project $project): void
    {
        unset($this->runtimeStarted[$project->rootPath]);
    }

    public function removeProject(Project $project): void
    {
        $this->invalidateRuntime($project);
    }

    private function startRuntime(Project $project): void
    {
        $configuration = hash('sha256', serialize([
            $this->configuration->phpCommand($project),
            $this->configuration->containerProjectRoot($project),
            $this->configuration->environment($project),
            $this->configuration->debug($project),
            $this->configuration->runtimeIndexing($project),
        ]));
        if (($this->runtimeStarted[$project->rootPath] ?? null) === $configuration) {
            return;
        }

        $this->runtimeInitializer->initialize($project);
        if ('ready' === $this->statuses->status($project)['runtime']['state']) {
            $this->runtimeStarted[$project->rootPath] = $configuration;
        } else {
            unset($this->runtimeStarted[$project->rootPath]);
        }
    }
}
