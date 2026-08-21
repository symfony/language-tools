<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<EnvironmentSourceFacts> */
final class EnvironmentIndex extends AbstractSourceFactsIndex
{
    /** @var array<string, string> */
    private array $processors = [];
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
}
