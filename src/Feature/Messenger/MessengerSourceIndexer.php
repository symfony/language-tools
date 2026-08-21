<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<MessengerSourceFacts> */
final class MessengerSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(private readonly MessengerSourceIndexRegistry $indexes, private readonly MessengerExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'messenger';
    }

    public function payloadClasses(): array
    {
        return [MessengerSourceFacts::class, MessengerSourceSymbol::class, MessengerSymbolKind::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof MessengerSourceFacts) {
            throw new \UnexpectedValueException('The Messenger source facts are invalid.');
        }

        return [
            ...array_filter($data->symbols(), static fn (MessengerSourceSymbol $symbol): bool => $symbol->isDeclaration()),
            $data->parents(),
            $data->handlers(),
        ];
    }

    protected function factsClass(): string
    {
        return MessengerSourceFacts::class;
    }

    protected function sourceIndex(Project $project): MessengerSourceIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): MessengerSourceFacts
    {
        return $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
    }
}
