<?php

namespace Symfony\Lsp\Protocol;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
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
        private readonly PositionConverter $positions,
    ) {
    }

    /** @param array<array-key, mixed> $params */
    public function document(array $params): ?DocumentRequest
    {
        $uri = $this->uri($params);

        return null === $uri ? null : $this->forUri($uri);
    }

    /** @param array<array-key, mixed> $params */
    public function uri(array $params): ?string
    {
        $textDocument = $params['textDocument'] ?? null;
        $uri = \is_array($textDocument) ? $textDocument['uri'] ?? null : null;

        return \is_string($uri) ? $uri : null;
    }

    public function forUri(string $uri): ?DocumentRequest
    {
        $document = $this->documents->get($uri);
        $project = $this->projects->forDocumentUri($uri);

        return null === $document || null === $project ? null : new DocumentRequest($document, $project, SourceDocument::fromDocument($document));
    }

    /** @param array<array-key, mixed> $params */
    public function positioned(array $params): ?PositionedRequest
    {
        $request = $this->document($params);
        $position = $params['position'] ?? null;
        if (null === $request || !\is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? null;
        $character = $position['character'] ?? null;
        if (!\is_int($line) || !\is_int($character) || $line < 0 || $character < 0) {
            return null;
        }

        $position = new Position($line, $character);

        return new PositionedRequest($request, $position, $this->positions->toByteOffset($request->document->text, $position));
    }

    /** @param array<array-key, mixed> $params */
    public function rename(array $params): ?RenameRequest
    {
        $newName = $params['newName'] ?? null;
        $request = $this->positioned($params);

        return !\is_string($newName) || '' === $newName || null === $request ? null : new RenameRequest($request, $newName);
    }
}
