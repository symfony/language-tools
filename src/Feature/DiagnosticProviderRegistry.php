<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectStateInterface;
use Symfony\Lsp\Runtime\RuntimeRefreshObserverInterface;
use Symfony\Lsp\Server\ServerLogger;

final class DiagnosticProviderRegistry implements RuntimeRefreshObserverInterface, ProjectStateInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly DiagnosticCollector $collector,
        private readonly ServerLogger $logger,
    ) {
    }

    public function publish(string $uri): void
    {
        $document = $this->documents->get($uri);
        if (null === $document || null === $collection = $this->collector->collect($uri)) {
            return;
        }
        foreach ($collection->failures as $failure) {
            $this->logger->error($failure->error, \sprintf('The "%s" diagnostic provider failed', $failure->provider));
        }

        $this->client->notify('textDocument/publishDiagnostics', [
            'uri' => $document->uri,
            'version' => $document->version,
            'diagnostics' => array_map(static fn (CollectedDiagnostic $diagnostic): array => $diagnostic->diagnostic, $collection->diagnostics),
        ]);
    }

    public function removeProject(Project $project): void
    {
        $rootUri = rtrim($project->rootUri, '/').'/';
        foreach ($this->documents->all() as $document) {
            if (!str_starts_with($document->uri, $rootUri)) {
                continue;
            }
            if (null === $this->projects->forDocumentUri($document->uri)) {
                $this->clear($document->uri);
            } else {
                $this->publish($document->uri);
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
            $documentProject = $this->projects->forDocumentUri($document->uri);
            if (null !== $documentProject && $documentProject->rootPath === $project->rootPath) {
                $this->publish($document->uri);
            }
        }
    }

    public function clear(string $uri): void
    {
        $this->client->notify('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'diagnostics' => [],
        ]);
    }
}
