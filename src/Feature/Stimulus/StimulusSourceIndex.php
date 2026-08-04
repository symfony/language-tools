<?php

namespace Symfony\Lsp\Feature\Stimulus;

final class StimulusSourceIndex
{
    /** @var array<string, StimulusSourceFacts> */
    private array $sources = [];
    /** @var array<string, StimulusSourceFacts> */
    private array $overlays = [];

    public function replace(StimulusSourceFacts ...$sources): void
    {
        $this->sources = [];
        foreach ($sources as $source) {
            $this->sources[$source->uri()] = $source;
        }
    }

    public function replaceSource(StimulusSourceFacts $source): void
    {
        $this->sources[$source->uri()] = $source;
    }

    public function removeSource(string $uri): void
    {
        unset($this->sources[$uri]);
    }

    public function overlay(StimulusSourceFacts $source): void
    {
        $this->overlays[$source->uri()] = $source;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /** @return list<StimulusControllerDeclaration> */
    public function declarations(?string $name = null): array
    {
        $declarations = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                if (null === $name || $name === $declaration->name()) {
                    $declarations[] = $declaration;
                }
            }
        }

        return $declarations;
    }

    /** @return list<StimulusReference> */
    public function references(string $controller, ?StimulusMemberKind $kind = null, ?string $member = null): array
    {
        $references = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->references() as $reference) {
                if ($controller === $reference->controller()
                    && $kind === $reference->kind()
                    && $member === $reference->member()
                ) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    /** @return list<StimulusSourceFacts> */
    private function facts(): array
    {
        return [...array_values(array_diff_key($this->sources, $this->overlays)), ...array_values($this->overlays)];
    }
}
