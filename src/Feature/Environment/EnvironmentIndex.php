<?php

namespace Symfony\Lsp\Feature\Environment;

final class EnvironmentIndex
{
    /** @var array<string, string> */
    private array $processors = [];
    /** @var array<string, EnvironmentSourceFacts> */
    private array $sources = [];
    /** @var array<string, EnvironmentSourceFacts> */
    private array $overlays = [];
    private bool $processorsComplete = false;

    /** @param array<string, string> $processors */
    public function replaceProcessors(array $processors, bool $complete = true): void
    {
        ksort($processors);
        $this->processors = $processors;
        $this->processorsComplete = $complete;
    }

    public function replaceSources(EnvironmentSourceFacts ...$facts): void
    {
        $this->sources = [];
        foreach ($facts as $source) {
            $this->sources[$source->uri()] = $source;
        }
    }

    public function replaceSource(EnvironmentSourceFacts $facts): void
    {
        $this->sources[$facts->uri()] = $facts;
    }

    public function removeSource(string $uri): void
    {
        unset($this->sources[$uri]);
    }

    public function overlay(EnvironmentSourceFacts $facts): void
    {
        $this->overlays[$facts->uri()] = $facts;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /** @return array<string, string> */
    public function processors(): array
    {
        return $this->processors;
    }

    public function processorsComplete(): bool
    {
        return $this->processorsComplete;
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                $names[$declaration->name()] = true;
            }
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /** @return list<EnvironmentDeclaration> */
    public function declarations(string $name): array
    {
        $result = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                if ($declaration->name() === $name) {
                    $result[] = $declaration;
                }
            }
        }

        return $result;
    }

    /** @return list<EnvironmentReference> */
    public function references(string $name): array
    {
        $result = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->references() as $reference) {
                if ($reference->name() === $name) {
                    $result[] = $reference;
                }
            }
        }

        return $result;
    }

    /** @return list<EnvironmentSourceFacts> */
    private function facts(): array
    {
        return [...array_values(array_diff_key($this->sources, $this->overlays)), ...array_values($this->overlays)];
    }
}
