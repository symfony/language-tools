<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Index\SourceFileChange;
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

    public function refreshUri(string $uri, SourceFileChange $sourceFileChange): void
    {
        if (SourceFileChange::ContentOnly === $sourceFileChange || SourceFileChange::Unchanged === $sourceFileChange) {
            return;
        }

        $project = $this->projects->forDocumentUri($uri);
        $path = null === $project ? null : $this->pathResolver->relative($project, $uri);
        if (null === $project
            || !$this->configuration->runtimeIndexing($project)
            || TrustStatus::Trusted !== $this->workspaceTrust->status($project)
            || null === $path
            || !$this->affectsRuntime($path)
        ) {
            return;
        }

        $this->statuses->runtimeStale($project);
        $this->refreshScheduler->schedule($project, $this->refreshMode($path));
    }

    private function refreshMode(string $path): RuntimeRefreshMode
    {
        return str_contains('/'.$path, '/translations/') ? RuntimeRefreshMode::Warmup : RuntimeRefreshMode::Clear;
    }

    private function affectsRuntime(string $path): bool
    {
        if (str_starts_with($path, 'var/') || str_starts_with($path, 'vendor/')) {
            return false;
        }

        $extension = Path::getExtension($path, true);
        if ('php' === $extension || 'composer.json' === basename($path)) {
            return true;
        }

        if (str_starts_with($path, 'assets/')) {
            return true;
        }
        if ('xml' === $extension) {
            return str_starts_with($path, 'config/');
        }
        if (\in_array($extension, ['json', 'xlf', 'xliff'], true)) {
            return str_contains('/'.$path, '/translations/');
        }
        if (!\in_array($extension, ['yaml', 'yml'], true)) {
            return false;
        }

        return str_starts_with($path, 'config/') || str_contains('/'.$path, '/translations/');
    }
}
