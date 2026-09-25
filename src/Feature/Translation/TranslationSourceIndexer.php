<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<TranslationSourceFacts> */
final class TranslationSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(TranslationIndexRegistry $indexes, private readonly TranslationExtractor $extractor)
    {
        parent::__construct($indexes, 'translations', TranslationSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [TranslationDeclaration::class, TranslationReference::class];
    }

    protected function extract(Project $project, SourceDocument $document): TranslationSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return $facts->declarations;
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): TranslationSourceFacts
    {
        return new TranslationSourceFacts(
            $current->uri,
            $healthy->declarations,
            $current->references,
            $healthy->globalParameters,
            $healthy->dynamicGlobalParameters,
        );
    }
}
