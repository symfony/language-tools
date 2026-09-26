<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Protocol\DocumentRequest;
use Symfony\Lsp\Protocol\LspRequestFactory;

/**
 * Builds the typed requests a capability registry hands to its providers, for
 * the documents a test opened.
 */
final class ProviderRequests
{
    private readonly LspRequestFactory $factory;

    public function __construct(DocumentStore $documents, ProjectRegistry $projects)
    {
        $this->factory = new LspRequestFactory($documents, $projects);
    }

    public function document(string $uri): DocumentRequest
    {
        return $this->factory->document(LspRequests::document($uri))
            ?? throw new \InvalidArgumentException(\sprintf('The document "%s" is not open in a project.', $uri));
    }
}
