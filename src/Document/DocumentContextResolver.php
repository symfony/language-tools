<?php

namespace Symfony\Lsp\Document;

use Symfony\Lsp\Project\ProjectRegistry;

final class DocumentContextResolver
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
    ) {
    }

    /** @param array<array-key, mixed> $params */
    public function resolveDocument(array $params): ?DocumentContext
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return null;
        }

        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project) {
            return null;
        }

        return new DocumentContext($document, $project);
    }

    /** @param array<array-key, mixed> $params */
    public function resolvePositioned(array $params): ?PositionedDocumentContext
    {
        $context = $this->resolveDocument($params);
        $position = $params['position'] ?? null;
        if (null === $context
            || !\is_array($position)
            || !\is_int($position['line'] ?? null)
            || !\is_int($position['character'] ?? null)
            || $position['line'] < 0
            || $position['character'] < 0
        ) {
            return null;
        }

        return new PositionedDocumentContext($context->document, $context->project, new Position($position['line'], $position['character']));
    }
}
