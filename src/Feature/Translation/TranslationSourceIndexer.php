<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<TranslationSourceFacts> */
final class TranslationSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(private readonly TranslationIndexRegistry $indexes, private readonly TranslationExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'translations';
    }

    public function payloadClasses(): array
    {
        return [TranslationDeclaration::class, TranslationReference::class, TranslationSourceFacts::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof TranslationSourceFacts) {
            throw new \UnexpectedValueException('The translation source facts are invalid.');
        }

        return $data->declarations();
    }

    protected function factsClass(): string
    {
        return TranslationSourceFacts::class;
    }

    protected function sourceIndex(Project $project): TranslationIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): TranslationSourceFacts
    {
        return $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
    }
}
