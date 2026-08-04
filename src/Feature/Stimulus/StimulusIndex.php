<?php

namespace Symfony\Lsp\Feature\Stimulus;

final class StimulusIndex
{
    /** @var array<string, StimulusController> */
    private array $controllers = [];
    private bool $complete = false;

    public function replace(bool $complete, StimulusController ...$controllers): void
    {
        $this->controllers = [];
        foreach ($controllers as $controller) {
            $this->controllers[$controller->name()] = $controller;
        }
        ksort($this->controllers);
        $this->complete = $complete;
    }

    public function controller(string $name): ?StimulusController
    {
        return $this->controllers[$name] ?? null;
    }

    /** @return list<StimulusController> */
    public function controllers(): array
    {
        return array_values($this->controllers);
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
