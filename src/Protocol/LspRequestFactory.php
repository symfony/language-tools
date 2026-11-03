<?php

namespace Symfony\Lsp\Protocol;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\ProjectRegistry;

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
        $position = $this->boundary($params['position'] ?? null);

        return null === $request || null === $position
            ? null
            : new PositionedRequest($request, $position, $this->positions->toByteOffset($request->document->text, $position));
    }

    /**
     * The declaration of a symbol is reported unless the client asks for the
     * references alone.
     *
     * @param array<array-key, mixed> $params
     */
    public function references(array $params): ?ReferencesRequest
    {
        $request = $this->positioned($params);
        $context = $params['context'] ?? null;
        $includeDeclaration = \is_array($context) ? $context['includeDeclaration'] ?? null : null;

        return null === $request ? null : new ReferencesRequest($request, false !== $includeDeclaration);
    }

    /** @param array<array-key, mixed> $params */
    public function codeAction(array $params): ?CodeActionRequest
    {
        $request = $this->document($params);
        if (null === $request) {
            return null;
        }
        $context = $params['context'] ?? null;
        if (!\is_array($context)) {
            return null;
        }

        $diagnostics = [];
        foreach (\is_array($context['diagnostics'] ?? null) ? $context['diagnostics'] : [] as $diagnostic) {
            $code = \is_array($diagnostic) ? $diagnostic['code'] ?? null : null;
            $range = \is_array($diagnostic) ? $this->range($diagnostic['range'] ?? null) : null;
            if (\is_array($diagnostic) && \is_string($code) && null !== $range) {
                $diagnostics[] = new CodeActionDiagnostic($code, $range, $diagnostic);
            }
        }

        return new CodeActionRequest($request, $diagnostics);
    }

    /** @param array<array-key, mixed> $params */
    public function rename(array $params): ?RenameRequest
    {
        $newName = $params['newName'] ?? null;
        $request = $this->positioned($params);

        return !\is_string($newName) || '' === $newName || null === $request ? null : new RenameRequest($request, $newName);
    }

    private function range(mixed $range): ?Range
    {
        if (!\is_array($range)) {
            return null;
        }
        $start = $this->boundary($range['start'] ?? null);
        $end = $this->boundary($range['end'] ?? null);

        return null === $start || null === $end ? null : new Range($start, $end);
    }

    private function boundary(mixed $position): ?Position
    {
        if (!\is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        return !\is_int($line) || !\is_int($character) || $line < 0 || $character < 0 ? null : new Position($line, $character);
    }
}
