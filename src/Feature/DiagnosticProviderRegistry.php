<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectStateInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshObserverInterface;

final class DiagnosticProviderRegistry implements RuntimeRefreshObserverInterface, ProjectStateInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly DiagnosticCollector $collector,
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
        if (null === $document || null === $diagnostics = $this->collector->collect($params)) {
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
}
