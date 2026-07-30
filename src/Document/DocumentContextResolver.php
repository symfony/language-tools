<?php

namespace Symfony\Lsp\Document;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class DocumentContextResolver
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{Document, Project, Position}|null
     */
    public function resolve(array $params): ?array
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
        if (null === $document || null === $project) {
            return null;
        }

        return [$document, $project, new Position($position['line'], $position['character'])];
    }
}
