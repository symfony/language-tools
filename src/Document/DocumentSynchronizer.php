<?php

namespace Symfony\Lsp\Document;

final class DocumentSynchronizer
{
    public function __construct(
        private readonly DocumentStore $documentStore,
        private readonly PositionConverter $positionConverter,
    ) {
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function open(array $params): void
    {
        $document = $params['textDocument'] ?? null;
        if (!\is_array($document)
            || !\is_string($document['uri'] ?? null)
            || !\is_string($document['languageId'] ?? null)
            || !\is_int($document['version'] ?? null)
            || !\is_string($document['text'] ?? null)
        ) {
            return;
        }

        $this->documentStore->open(new Document(
            $document['uri'],
            $this->languageId($document['uri'], $document['languageId']),
            $document['version'],
            $document['text'],
        ));
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function change(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        $changes = $params['contentChanges'] ?? null;
        if (!\is_array($textDocument)
            || !\is_string($textDocument['uri'] ?? null)
            || !\is_int($textDocument['version'] ?? null)
            || !\is_array($changes)
        ) {
            return;
        }

        $document = $this->documentStore->get($textDocument['uri']);
        if (null === $document) {
            return;
        }

        $text = $document->text();
        foreach ($changes as $change) {
            if (!\is_array($change) || !\is_string($change['text'] ?? null)) {
                continue;
            }

            $range = $this->range($change['range'] ?? null);
            $text = null === $range
                ? $change['text']
                : $this->positionConverter->applyChange($text, $range, $change['text']);
        }

        $this->documentStore->update($textDocument['uri'], $textDocument['version'], $text);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function close(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (\is_array($textDocument) && \is_string($textDocument['uri'] ?? null)) {
            $this->documentStore->close($textDocument['uri']);
        }
    }

    private function languageId(string $uri, string $languageId): string
    {
        $path = parse_url($uri, \PHP_URL_PATH);

        return \is_string($path) && str_ends_with(strtolower($path), '.twig') ? 'twig' : $languageId;
    }

    private function range(mixed $value): ?Range
    {
        if (!\is_array($value)) {
            return null;
        }

        $start = $this->position($value['start'] ?? null);
        $end = $this->position($value['end'] ?? null);

        return null === $start || null === $end ? null : new Range($start, $end);
    }

    private function position(mixed $value): ?Position
    {
        if (!\is_array($value)
            || !\is_int($value['line'] ?? null)
            || !\is_int($value['character'] ?? null)
            || $value['line'] < 0
            || $value['character'] < 0
        ) {
            return null;
        }

        return new Position($value['line'], $value['character']);
    }
}
