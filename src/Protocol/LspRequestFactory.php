<?php

namespace Symfony\Lsp\Protocol;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\ProjectRegistry;

/**
 * Decodes the parameters of a text document request once, for every feature
 * provider the registry of a capability serves.
 */
final class LspRequestFactory
{
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly ProjectRegistry $projects,
    ) {
    }

    /** @param array<array-key, mixed> $params */
    public function document(array $params): ?DocumentRequest
    {
        $textDocument = $params['textDocument'] ?? null;
        $uri = \is_array($textDocument) ? $textDocument['uri'] ?? null : null;
        if (!\is_string($uri)) {
            return null;
        }

        return $this->forUri($uri);
    }

    public function forUri(string $uri): ?DocumentRequest
    {
        $document = $this->documents->get($uri);
        $project = $this->projects->forDocumentUri($uri);

        return null === $document || null === $project ? null : new DocumentRequest($document, $project, SourceDocument::fromDocument($document));
    }
}
