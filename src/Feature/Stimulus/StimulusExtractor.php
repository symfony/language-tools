<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

final class StimulusExtractor
{
    public function __construct(
        private readonly StimulusControllerExtractor $controllers,
        private readonly StimulusReferenceExtractor $references,
        private readonly StimulusCompletionContextResolver $completionContexts,
    ) {
    }

    public function extract(Project $project, SourceDocument $document): StimulusSourceFacts
    {
        if (\in_array($document->languageId, ['javascript', 'typescript'], true)) {
            return new StimulusSourceFacts($document->uri, $this->controllers->extract($project, $document->uri, $document->text), $this->references->extractJavaScript($document->uri, $document->text));
        }
        if ('twig' === $document->languageId) {
            return new StimulusSourceFacts($document->uri, [], $this->references->extractTwig($document->uri, $document->text));
        }

        return new StimulusSourceFacts($document->uri, [], []);
    }

    public function completionContext(string $languageId, string $text, int $offset): ?StimulusCompletionContext
    {
        return $this->completionContexts->resolve($languageId, $text, $offset);
    }
}
