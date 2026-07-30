<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
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

        $context = RouteCompletionContext::fromPhp(
            $document->text(),
            new Position($position['line'], $position['character']),
            $this->positionConverter,
        );
        if (null === $context) {
            return null;
        }

        $range = $context->replacementRange();

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
            (new RouteCompletionProvider($this->routeIndexes->forProject($project)))->complete($context->prefix()),
        );
    }
}
