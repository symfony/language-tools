<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\TrustStatus;
use Symfony\Lsp\Project\WorkspaceTrust;

final class ProjectRuntimeRefresher
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly WorkspaceTrust $workspaceTrust,
        private readonly RuntimeInitializerInterface $runtimeInitializer,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function refreshAfterSave(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $project
            || TrustStatus::Trusted !== $this->workspaceTrust->status($project)
            || !$this->affectsRoutes($project, $textDocument['uri'])
        ) {
            return;
        }

        $this->runtimeInitializer->initialize($project);
    }

    private function affectsRoutes(Project $project, string $uri): bool
    {
        $path = parse_url($uri, \PHP_URL_PATH);
        if (!\is_string($path)) {
            return false;
        }

        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if ('php' === $extension) {
            return true;
        }

        if (!\in_array($extension, ['yaml', 'yml'], true)) {
            return false;
        }

        $rootPath = rtrim(str_replace('\\', '/', $project->rootPath()), '/');
        $path = str_replace('\\', '/', rawurldecode($path));

        return str_starts_with($path, $rootPath.'/config/');
    }
}
