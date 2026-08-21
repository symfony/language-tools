<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<TwigComponentSourceFacts> */
final class TwigComponentIndex extends AbstractSourceFactsIndex
{
    private bool $complete = false;
    private bool $runtimeComplete = false;
    /** @var array<string, true> */
    private array $runtimeNames = [];
    /** @var array<string, true> */
    private array $caseInsensitiveRuntimeNames = [];
    private string $anonymousTemplateDirectory = 'components';

    /** @return list<TwigComponent> */
    public function components(): array
    {
        $components = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->components() as $component) {
                $components[$component->name()] = $this->merge($components[$component->name()] ?? null, $component);
            }
        }
        ksort($components);

        return array_values($components);
    }

    /** @return list<TwigComponent> */
    public function declarations(string $name): array
    {
        $components = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->components() as $component) {
                if ($component->name() === $name) {
                    $components[] = $component;
                }
            }
        }

        return $components;
    }

    public function get(string $name): ?TwigComponent
    {
        foreach ($this->components() as $component) {
            if ($component->name() === $name) {
                return $component;
            }
        }

        return null;
    }

    /** @return list<TwigComponentReference> */
    public function references(string $name): array
    {
        $references = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->references() as $reference) {
                if ($reference->name() === $name) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    /** @return list<TwigComponentActionReference> */
    public function actionReferences(string $component, string $action): array
    {
        $references = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->actionReferences() as $reference) {
                if ($component === $reference->component() && $action === $reference->action()) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    /** @return list<LiveComponentEvent> */
    public function events(string $name): array
    {
        $events = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->events() as $event) {
                if ($name === $event->name()) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /** @return list<string> */
    public function eventNames(): array
    {
        $names = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->events() as $event) {
                if ($event->isDeclaration()) {
                    $names[] = $event->name();
                }
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * @param list<string> $names
     * @param list<string> $caseInsensitiveNames
     */
    public function replaceRuntime(bool $complete, array $names, string $anonymousTemplateDirectory, array $caseInsensitiveNames = []): void
    {
        $this->runtimeComplete = $complete;
        $this->runtimeNames = array_fill_keys($names, true);
        $this->caseInsensitiveRuntimeNames = array_fill_keys(array_map('strtolower', $caseInsensitiveNames), true);
        $this->anonymousTemplateDirectory = $anonymousTemplateDirectory;
    }

    public function isRuntimeComplete(): bool
    {
        return $this->runtimeComplete;
    }

    public function hasRuntimeName(string $name): bool
    {
        return isset($this->runtimeNames[$name]) || isset($this->caseInsensitiveRuntimeNames[strtolower($name)]);
    }

    /** @return list<string> */
    public function runtimeNames(): array
    {
        return array_keys($this->runtimeNames);
    }

    public function anonymousTemplateDirectory(): string
    {
        return $this->anonymousTemplateDirectory;
    }

    protected function factsReplaced(): void
    {
        $this->complete = true;
    }

    private function merge(?TwigComponent $current, TwigComponent $component): TwigComponent
    {
        if (null === $current) {
            return $component;
        }

        $actions = [];
        foreach ([...$current->actions(), ...$component->actions()] as $action) {
            $actions[$action->name()] = $action;
        }

        return new TwigComponent(
            $component->name(),
            null !== $component->className() ? $component->uri() : $current->uri(),
            null !== $component->className() ? $component->range() : $current->range(),
            $component->className() ?? $current->className(),
            $component->template() ?? $current->template(),
            array_values(array_unique([...$current->properties(), ...$component->properties()])),
            $current->isLive() || $component->isLive(),
            array_values($actions),
        );
    }
}
