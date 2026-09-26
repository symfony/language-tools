<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\CodeActionRequest;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspRequestFactory;
use Symfony\Lsp\Protocol\PositionedRequest;
use Symfony\Lsp\Protocol\ReferencesRequest;
use Symfony\Lsp\Protocol\RenameRequest;

/**
 * Builds the typed requests a capability registry hands to its providers, for
 * the documents a test opened.
 */
final class ProviderRequests
{
    private readonly LspRequestFactory $factory;

    public function __construct(DocumentStore $documents, ProjectRegistry $projects, ?PositionConverter $positions = null)
    {
        $this->factory = new LspRequestFactory($documents, $projects, $positions ?? new PositionConverter());
    }

    public function document(string $uri): DocumentRequest
    {
        return $this->factory->document(LspRequests::document($uri))
            ?? throw new \InvalidArgumentException(\sprintf('The document "%s" is not open in a project.', $uri));
    }

    /** @param array<array-key, mixed> $params */
    public function positioned(array $params): PositionedRequest
    {
        return $this->factory->positioned($params)
            ?? throw new \InvalidArgumentException('The request does not point at an open document of a project.');
    }

    /** @param array<array-key, mixed> $params */
    public function references(array $params, bool $includeDeclaration = true): ReferencesRequest
    {
        return new ReferencesRequest($this->positioned($params), $includeDeclaration);
    }

    /** @param list<array<array-key, mixed>> $diagnostics */
    public function codeAction(string $uri, array $diagnostics = []): CodeActionRequest
    {
        return $this->factory->codeAction([...LspRequests::document($uri), 'context' => ['diagnostics' => $diagnostics]])
            ?? throw new \InvalidArgumentException(\sprintf('The document "%s" is not open in a project.', $uri));
    }

    /** @param array<array-key, mixed> $params */
    public function rename(array $params, string $newName): RenameRequest
    {
        return new RenameRequest($this->positioned($params), $newName);
    }
}
