<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Runtime\RuntimeRefreshObserverInterface;

final class DiagnosticProviderRegistry implements RuntimeRefreshObserverInterface
{
    /** @var list<DiagnosticProviderInterface> */
    private array $providers;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        DiagnosticProviderInterface ...$providers,
    ) {
        $this->providers = array_values($providers);
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
