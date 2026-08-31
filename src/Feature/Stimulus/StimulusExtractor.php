<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Project\Project;

final class StimulusExtractor
{
    public function __construct(
        private readonly StimulusControllerExtractor $controllers,
        private readonly StimulusReferenceExtractor $references,
        private readonly StimulusCompletionContextResolver $completionContexts,
    ) {
    }

    public function extract(Project $project, string $uri, string $languageId, string $text): StimulusSourceFacts
    {
        if (\in_array($languageId, ['javascript', 'typescript'], true)) {
            return new StimulusSourceFacts($uri, $this->controllers->extract($project, $uri, $text), $this->references->extractJavaScript($uri, $text));
        }
        if ('twig' === $languageId) {
            return new StimulusSourceFacts($uri, [], $this->references->extractTwig($uri, $text));
        }

        return new StimulusSourceFacts($uri, [], []);
    }

    public function completionContext(string $languageId, string $text, int $offset): ?StimulusCompletionContext
    {
        return $this->completionContexts->resolve($languageId, $text, $offset);
    }
}
