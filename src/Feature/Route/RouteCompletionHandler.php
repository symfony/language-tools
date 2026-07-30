<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Project\ProjectRegistry;

final class RouteCompletionHandler
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly PositionConverter $positionConverter,
        private readonly ProjectRegistry $projects,
        private readonly RouteIndexRegistry $routeIndexes,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return list<array{label: string, kind: int, detail: string, textEdit: array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}}>|null
     */
    public function complete(array $params): ?array
    {
        $textDocument = $params['textDocument'] ?? null;
        $position = $params['position'] ?? null;
        if (!\is_array($textDocument)
            || !\is_string($textDocument['uri'] ?? null)
            || !\is_array($position)
            || !\is_int($position['line'] ?? null)
            || !\is_int($position['character'] ?? null)
            || $position['line'] < 0
            || $position['character'] < 0
        ) {
            return null;
        }

        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project || 'php' !== $document->languageId()) {
            return null;
        }

        $position = new Position($position['line'], $position['character']);
        $routeIndex = $this->routeIndexes->forProject($project);
        $parameterContext = RouteParameterCompletionContext::fromPhp(
            $document->text(),
            $position,
            $this->positionConverter,
        );
        if (null !== $parameterContext) {
            $route = $routeIndex->get($parameterContext->routeName());
            if (null === $route) {
                return [];
            }

            $items = array_map(
                static fn (string $parameter): array => [
                    'label' => $parameter,
                    'kind' => 10,
                    'detail' => \sprintf('Parameter of route %s', $route->name()),
                ],
                array_values(array_filter(
                    $route->parameters(),
                    static fn (string $parameter): bool => str_starts_with($parameter, $parameterContext->prefix()),
                )),
            );

            return $this->withTextEdits($items, $parameterContext->replacementRange());
        }

        $routeContext = RouteCompletionContext::fromPhp(
            $document->text(),
            $position,
            $this->positionConverter,
        );
        if (null === $routeContext) {
            return null;
        }

        return $this->withTextEdits(
            (new RouteCompletionProvider($routeIndex))->complete($routeContext->prefix()),
            $routeContext->replacementRange(),
        );
    }

    /**
     * @param list<array{label: string, kind: int, detail: string}> $items
     *
     * @return list<array{label: string, kind: int, detail: string, textEdit: array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}}>
     */
    private function withTextEdits(array $items, Range $range): array
    {
        return array_map(
            static fn (array $item): array => [
                ...$item,
                'textEdit' => [
                    'range' => [
                        'start' => [
                            'line' => $range->start()->line(),
                            'character' => $range->start()->character(),
                        ],
                        'end' => [
                            'line' => $range->end()->line(),
                            'character' => $range->end()->character(),
                        ],
                    ],
                    'newText' => $item['label'],
                ],
            ],
            $items,
        );
    }
}
