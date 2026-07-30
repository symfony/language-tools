<?php

namespace Symfony\Lsp\Document;

final class DocumentStore
{
    /** @var array<string, Document> */
    private array $documents = [];

    public function open(Document $document): void
    {
        $this->documents[$document->uri()] = $document;
    }

    public function update(string $uri, int $version, string $text): void
    {
        $document = $this->documents[$uri] ?? null;
        if (null === $document) {
            throw new \InvalidArgumentException(\sprintf('Document "%s" is not open.', $uri));
        }

        $this->documents[$uri] = new Document($uri, $document->languageId(), $version, $text);
    }

    public function close(string $uri): void
    {
        unset($this->documents[$uri]);
    }

    public function get(string $uri): ?Document
    {
        return $this->documents[$uri] ?? null;
    }

    /**
     * @return list<Document>
     */
    public function all(): array
    {
        return array_values($this->documents);
    }
}
