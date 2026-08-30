<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<SecuritySourceFacts> */
final class SecuritySourceIndexer extends AbstractSourceIndexer
{
    public function __construct(private readonly SecuritySourceIndexRegistry $indexes, private readonly SecurityExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'security';
    }

    public function payloadClasses(): array
    {
        return [SecuritySourceFacts::class, SecuritySourceSymbol::class, SecuritySymbolKind::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof SecuritySourceFacts) {
            throw new \UnexpectedValueException('The security source facts are invalid.');
        }

        return array_values(array_filter($data->symbols, static fn (SecuritySourceSymbol $symbol): bool => $symbol->declaration));
    }

    protected function factsClass(): string
    {
        return SecuritySourceFacts::class;
    }

    protected function sourceIndex(Project $project): SecuritySourceIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): SecuritySourceFacts
    {
        return $this->extractor->extract($document->uri, $document->languageId, $document->text);
    }
}
