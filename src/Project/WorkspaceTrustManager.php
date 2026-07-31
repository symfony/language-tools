<?php

namespace Symfony\Lsp\Project;

use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Runtime\RuntimeInitializerInterface;

final class WorkspaceTrustManager
{
    /** @var array<string, true> */
    private array $runtimeStarted = [];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly WorkspaceTrust $workspaceTrust,
        private readonly RuntimeInitializerInterface $runtimeInitializer,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function applyInitializationOptions(array $params, ProjectRegistry $projects): void
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

        foreach ($projects->all() as $project) {
            $this->workspaceTrust->set($project, $status);
        }
    }

    public function requestUnknownDecisions(ProjectRegistry $projects): void
    {
        foreach ($projects->all() as $project) {
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
                    'Symfony LSP must execute application code to index runtime metadata for "%s".',
                    $project->rootPath(),
                ),
                'actions' => [
                    ['title' => 'Trust and enable runtime indexing'],
                    ['title' => 'Keep static-only mode'],
                ],
            ]);

            $trusted = \is_array($response)
                && 'Trust and enable runtime indexing' === ($response['title'] ?? null);
            $status = $trusted ? TrustStatus::Trusted : TrustStatus::Untrusted;
            $this->workspaceTrust->set($project, $status);
            if (TrustStatus::Trusted === $status) {
                $this->startRuntime($project);
            }
        }
    }

    private function startRuntime(Project $project): void
    {
        if (isset($this->runtimeStarted[$project->rootPath()])) {
            return;
        }

        $this->runtimeStarted[$project->rootPath()] = true;
        $this->runtimeInitializer->initialize($project);
    }
}
