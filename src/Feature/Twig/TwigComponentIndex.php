<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigComponentIndex
{
    /** @var array<string, TwigComponentSourceFacts> */
    private array $sources = [];
    /** @var array<string, TwigComponentSourceFacts> */
    private array $overlays = [];
    private bool $complete = false;

    public function replace(TwigComponentSourceFacts ...$sources): void
    {
        $this->sources = [];
        foreach ($sources as $source) {
            $this->sources[$source->uri()] = $source;
        }
        $this->complete = true;
    }

    public function replaceSource(TwigComponentSourceFacts $source): void
    {
        $this->sources[$source->uri()] = $source;
    }

    public function removeSource(string $uri): void
    {
        unset($this->sources[$uri]);
    }

    public function overlay(TwigComponentSourceFacts $source): void
    {
        $this->overlays[$source->uri()] = $source;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

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

    /** @return list<TwigComponentSourceFacts> */
    private function facts(): array
    {
        return [...array_values(array_diff_key($this->sources, $this->overlays)), ...array_values($this->overlays)];
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
