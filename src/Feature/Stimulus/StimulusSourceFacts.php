<?php

namespace Symfony\Lsp\Feature\Stimulus;

use Symfony\Lsp\Index\SourceFactsInterface;

final class StimulusSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<StimulusControllerDeclaration> $declarations
     * @param list<StimulusReference>             $references
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $declarations,
        public readonly array $references,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->references;
    }
}
