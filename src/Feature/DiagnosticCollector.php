<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Index\SourceFileEnumerator;
use Symfony\Lsp\Project\ProjectPathResolver;
use Symfony\Lsp\Project\ProjectRegistry;

final class DiagnosticCollector
{
    /** @param iterable<DiagnosticProviderInterface> $providers */
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly ProjectPathResolver $pathResolver,
        private readonly iterable $providers,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function collect(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }

        $document = $this->documents->get($textDocument['uri']);
        if (null === $document) {
            return null;
        }
        if ($this->isDependencyOwned($document->uri())) {
            return [];
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

        return $matched ? $diagnostics : null;
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
