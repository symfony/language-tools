<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<EnvironmentSourceFacts> */
final class EnvironmentIndex extends AbstractSourceFactsIndex
{
    /** @var array<string, string> */
    private array $processors = [];
    private bool $processorsComplete = false;
    private bool $indexed = false;

    /** @var list<string> */
    private array $names = [];

    /** @var array<string, list<EnvironmentDeclaration>> */
    private array $declarations = [];

    /** @var array<string, list<EnvironmentReference>> */
    private array $references = [];

    /** @param array<string, string> $processors */
    public function replaceProcessors(array $processors, bool $complete = true): void
    {
        ksort($processors);
        $this->processors = $processors;
        $this->processorsComplete = $complete;
    }

    public function replaceSources(EnvironmentSourceFacts ...$facts): void
    {
        $this->replace(...$facts);
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
        $this->index();

        return $this->names;
    }

    /** @return list<EnvironmentDeclaration> */
    public function declarations(string $name): array
    {
        $this->index();

        return $this->declarations[$name] ?? [];
    }

    /** @return list<EnvironmentReference> */
    public function references(string $name): array
    {
        $this->index();

        return $this->references[$name] ?? [];
    }

    protected function factsChanged(): void
    {
        $this->indexed = false;
    }

    private function index(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->declarations = [];
        $this->references = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations() as $declaration) {
                $this->declarations[$declaration->name()][] = $declaration;
            }
            foreach ($facts->references() as $reference) {
                $this->references[$reference->name()][] = $reference;
            }
        }

        $this->names = array_keys($this->declarations);
        sort($this->names);
        $this->indexed = true;
    }
}
