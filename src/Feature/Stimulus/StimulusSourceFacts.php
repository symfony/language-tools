<?php

namespace Symfony\Lsp\Feature\Stimulus;

final class StimulusSourceFacts
{
    /**
     * @param list<StimulusControllerDeclaration> $declarations
     * @param list<StimulusReference>             $references
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $declarations,
        private readonly array $references,
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<StimulusControllerDeclaration> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /** @return list<StimulusReference> */
    public function references(): array
    {
        return $this->references;
    }
}
