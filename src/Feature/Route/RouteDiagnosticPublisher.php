<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Project\ProjectRegistry;

final class RouteDiagnosticPublisher
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly RouteIndexRegistry $routeIndexes,
        private readonly RouteReferenceExtractor $referenceExtractor,
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

        $uri = $textDocument['uri'];
        $document = $this->documents->get($uri);
        $project = $this->projects->forDocumentUri($uri);
        if (null === $document || null === $project || 'php' !== $document->languageId()) {
            return;
        }

        $routeIndex = $this->routeIndexes->forProject($project);
        if (!$routeIndex->isComplete()) {
            return;
        }

        $diagnostics = [];
        foreach ($this->referenceExtractor->extract($document->text()) as $reference) {
            $range = [
                'start' => [
                    'line' => $reference->range()->start()->line(),
                    'character' => $reference->range()->start()->character(),
                ],
                'end' => [
                    'line' => $reference->range()->end()->line(),
                    'character' => $reference->range()->end()->character(),
                ],
            ];
            $route = $routeIndex->get($reference->name());
            if (null === $route) {
                $diagnostics[] = [
                    'range' => $range,
                    'severity' => 1,
                    'source' => 'symfony',
                    'code' => 'route.not_found',
                    'message' => \sprintf('Route "%s" does not exist in the selected environment.', $reference->name()),
                ];

                continue;
            }

            if (null === $reference->providedParameters()) {
                continue;
            }

            $missingParameters = array_values(array_diff(
                $route->requiredParameters(),
                $reference->providedParameters(),
            ));
            if ([] !== $missingParameters) {
                $diagnostics[] = [
                    'range' => $range,
                    'severity' => 1,
                    'source' => 'symfony',
                    'code' => 'route.missing_parameters',
                    'message' => \sprintf(
                        'Route "%s" requires parameter%s "%s".',
                        $reference->name(),
                        1 === \count($missingParameters) ? '' : 's',
                        implode('", "', $missingParameters),
                    ),
                ];
            }
        }

        $this->client->notify('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'version' => $document->version(),
            'diagnostics' => $diagnostics,
        ]);
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
