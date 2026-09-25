<?php

namespace Symfony\Lsp\Feature;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Project\ProjectFileScopeRegistry;
use Symfony\Lsp\Project\ProjectPathPolicy;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class DiagnosticCollector
{
    /** @param iterable<DiagnosticProviderInterface> $providers */
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly ProjectFileScopeRegistry $fileScope,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly ProjectPathPolicy $paths,
        private readonly PartialParseDiagnosticFilter $partialParseFilter,
        private readonly DiagnosticSuppressor $suppressor,
        private readonly iterable $providers,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function collect(array $params, bool $includeExcluded = false, bool $measureProviders = false): ?DetailedDiagnosticCollection
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }

        $document = $this->documents->get($textDocument['uri']);
        if (null === $document) {
            return null;
        }
        if ($this->isExcluded($document->uri, $includeExcluded)) {
            return new DetailedDiagnosticCollection([], []);
        }

        $diagnostics = [];
        $failures = [];
        $providerNanoseconds = [];
        $matched = false;
        foreach ($this->providers as $provider) {
            $providerName = $measureProviders ? $provider->name() : null;
            $providerStartedAt = $measureProviders ? (float) hrtime(true) : null;
            try {
                try {
                    $providedDiagnostics = $provider->diagnostics($params);
                } catch (\Throwable $error) {
                    $failures[] = new DiagnosticProviderFailure($providerName ?? $provider->name(), $error);

                    continue;
                }
                if (null === $providedDiagnostics) {
                    continue;
                }

                $providerName ??= $provider->name();
                $provided = [];
                try {
                    foreach ($providedDiagnostics as $diagnostic) {
                        $provided[] = $this->collectedDiagnostic($providerName, $diagnostic);
                    }
                } catch (\Throwable $error) {
                    $failures[] = new DiagnosticProviderFailure($providerName, $error);

                    continue;
                }

                $matched = true;
                array_push($diagnostics, ...$provided);
            } finally {
                if (null !== $providerStartedAt && null !== $providerName) {
                    $providerNanoseconds[$providerName] = ($providerNanoseconds[$providerName] ?? 0.0) + max(0.0, (float) hrtime(true) - $providerStartedAt);
                }
            }
        }

        $diagnostics = $this->suppressor->suppress($document, $this->partialParseFilter->filter($document, $diagnostics));
        if (!$matched && [] === $diagnostics && [] === $failures) {
            return null;
        }

        return new DetailedDiagnosticCollection($diagnostics, $failures, $providerNanoseconds);
    }

    private function collectedDiagnostic(string $provider, mixed $diagnostic): CollectedDiagnostic
    {
        if (!\is_array($diagnostic)) {
            throw new \UnexpectedValueException('A diagnostic provider returned a non-array diagnostic.');
        }

        return new CollectedDiagnostic($provider, $diagnostic);
    }

    private function isExcluded(string $uri, bool $includeExcluded): bool
    {
        $project = $this->projects->forDocumentUri($uri);
        if (null === $project) {
            return false;
        }
        $path = $this->uriToPathConverter->convert($uri);
        if (null === $path) {
            return false;
        }
        if (!$includeExcluded && $this->fileScope->isExcluded($project, $path)) {
            return true;
        }

        return $this->paths->isExcluded($project, $path);
    }
}
