<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Feature\DiagnosticProviderInterface;
use Symfony\Lsp\Project\ProjectRegistry;

final class RouteDiagnosticPublisher implements DiagnosticProviderInterface
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
        private readonly RouteIndexRegistry $routeIndexes,
        private readonly RouteReferenceExtractor $phpReferenceExtractor,
        private readonly TwigRouteReferenceExtractor $twigReferenceExtractor,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array<array-key, mixed>>|null
     */
    public function diagnostics(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }

        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project || !\in_array($document->languageId(), ['php', 'twig'], true)) {
            return null;
        }

        $routeIndex = $this->routeIndexes->forProject($project);
        if (!$routeIndex->isComplete()) {
            return null;
        }

        $diagnostics = [];
        $references = 'twig' === $document->languageId()
            ? $this->twigReferenceExtractor->extract($document->text())
            : $this->phpReferenceExtractor->extract($document->text());
        foreach ($references as $reference) {
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

        return $diagnostics;
    }
}
