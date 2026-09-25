<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<StimulusSourceFacts> */
final class StimulusSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(StimulusSourceIndexRegistry $indexes, private readonly StimulusExtractor $extractor)
    {
        parent::__construct($indexes, 'stimulus', StimulusSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [StimulusControllerDeclaration::class, StimulusMember::class, StimulusMemberKind::class, StimulusReference::class];
    }

    protected function extract(Project $project, SourceDocument $document): StimulusSourceFacts
    {
        return $this->extractor->extract($project, $document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return $facts->declarations;
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): StimulusSourceFacts
    {
        return $current;
    }
}
