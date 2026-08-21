<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectStateInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshObserverInterface;

final class DiagnosticProviderRegistry implements RuntimeRefreshObserverInterface, ProjectStateInterface
{
    /** @param iterable<DiagnosticProviderInterface> $providers */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly ProjectPathResolver $pathResolver,
        private readonly iterable $providers,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function publish(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $document = $this->documents->get($textDocument['uri']);
        if (null === $document) {
            return;
        }

        if ($this->isDependencyOwned($document->uri())) {
            $this->client->notify('textDocument/publishDiagnostics', [
                'uri' => $document->uri(),
                'version' => $document->version(),
                'diagnostics' => [],
            ]);

            return;
        }

        $diagnostics = [];
        $matched = false;
        foreach ($this->providers as $provider) {
            $providedDiagnostics = $provider->diagnostics($params);
            if (null === $providedDiagnostics) {
                continue;
            }

            $matched = true;
            array_push($diagnostics, ...$providedDiagnostics);
        }

        if (!$matched) {
            return;
        }

        $this->client->notify('textDocument/publishDiagnostics', [
            'uri' => $document->uri(),
            'version' => $document->version(),
            'diagnostics' => $diagnostics,
        ]);
    }

    public function removeProject(Project $project): void
    {
        $rootUri = rtrim($project->rootUri(), '/').'/';
        foreach ($this->documents->all() as $document) {
            if (!str_starts_with($document->uri(), $rootUri)) {
                continue;
            }
            if (null === $this->projects->forDocumentUri($document->uri())) {
                $this->clear(['textDocument' => ['uri' => $document->uri()]]);
            } else {
                $this->publish(['textDocument' => ['uri' => $document->uri()]]);
            }
        }
    }

    public function refreshAll(): void
    {
        foreach ($this->projects->all() as $project) {
            $this->refreshed($project);
        }
    }

    public function refreshed(Project $project): void
    {
        foreach ($this->documents->all() as $document) {
            $documentProject = $this->projects->forDocumentUri($document->uri());
            if (null !== $documentProject && $documentProject->rootPath() === $project->rootPath()) {
                $this->publish(['textDocument' => ['uri' => $document->uri()]]);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function clear(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $this->client->notify('textDocument/publishDiagnostics', [
            'uri' => $textDocument['uri'],
            'diagnostics' => [],
        ]);
    }

    private function isDependencyOwned(string $uri): bool
    {
        $project = $this->projects->forDocumentUri($uri);
        if (null === $project) {
            return false;
        }
        $relativePath = $this->pathResolver->relative($project, $uri);
        if (null === $relativePath) {
            return false;
        }

        foreach (explode('/', $relativePath) as $segment) {
            if (\in_array($segment, SourceFileEnumerator::EXCLUDED_DIRECTORIES, true)) {
                return true;
            }
        }

        return false;
    }
}
