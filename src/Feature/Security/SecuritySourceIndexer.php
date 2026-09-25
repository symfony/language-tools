<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<SecuritySourceFacts> */
final class SecuritySourceIndexer extends AbstractSourceIndexer
{
    public function __construct(SecuritySourceIndexRegistry $indexes, private readonly SecurityExtractor $extractor)
    {
        parent::__construct($indexes, 'security', SecuritySourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [SecuritySourceSymbol::class, SecuritySymbolKind::class];
    }

    protected function extract(Project $project, SourceDocument $document): SecuritySourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return array_values(array_filter($facts->symbols, static fn (SecuritySourceSymbol $symbol): bool => $symbol->declaration));
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): SecuritySourceFacts
    {
        return new SecuritySourceFacts($current->uri, [
            ...array_filter($healthy->symbols, static fn (SecuritySourceSymbol $symbol): bool => $symbol->declaration),
            ...array_filter($current->symbols, static fn (SecuritySourceSymbol $symbol): bool => !$symbol->declaration),
        ]);
    }
}
