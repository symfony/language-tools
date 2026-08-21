<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<StimulusSourceFacts> */
final class StimulusSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(private readonly StimulusSourceIndexRegistry $indexes, private readonly StimulusExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'stimulus';
    }

    public function payloadClasses(): array
    {
        return [StimulusControllerDeclaration::class, StimulusMember::class, StimulusMemberKind::class, StimulusReference::class, StimulusSourceFacts::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof StimulusSourceFacts) {
            throw new \UnexpectedValueException('The Stimulus source facts are invalid.');
        }

        return $data->declarations();
    }

    protected function factsClass(): string
    {
        return StimulusSourceFacts::class;
    }

    protected function sourceIndex(Project $project): StimulusSourceIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): StimulusSourceFacts
    {
        return $this->extractor->extract($project, $document->uri(), $document->languageId(), $document->text());
    }
}
