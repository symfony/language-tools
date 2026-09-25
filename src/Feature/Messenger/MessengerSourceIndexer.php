<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<MessengerSourceFacts> */
final class MessengerSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(MessengerSourceIndexRegistry $indexes, private readonly MessengerExtractor $extractor)
    {
        parent::__construct($indexes, 'messenger', MessengerSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [MessengerSourceSymbol::class, MessengerSymbolKind::class];
    }

    protected function extract(Project $project, SourceDocument $document): MessengerSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return [
            ...array_filter($facts->symbols, static fn (MessengerSourceSymbol $symbol): bool => $symbol->declaration),
            $facts->parents,
            $facts->handlers,
        ];
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): MessengerSourceFacts
    {
        return new MessengerSourceFacts($current->uri, [
            ...array_filter($healthy->symbols, static fn (MessengerSourceSymbol $symbol): bool => $symbol->declaration),
            ...array_filter($current->symbols, static fn (MessengerSourceSymbol $symbol): bool => !$symbol->declaration),
        ], $healthy->parents, $healthy->handlers);
    }
}
