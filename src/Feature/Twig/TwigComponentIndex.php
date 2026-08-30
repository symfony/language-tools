<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<TwigComponentSourceFacts> */
final class TwigComponentIndex extends AbstractSourceFactsIndex
{
    private bool $complete = false;
    private bool $runtimeComplete = false;
    /** @var array<string, TwigComponent> */
    private array $runtimeComponents = [];
    /** @var array<string, TwigComponent> */
    private array $caseInsensitiveRuntimeComponents = [];
    /** @var array<string, true> */
    private array $runtimeNames = [];
    /** @var array<string, true> */
    private array $caseInsensitiveRuntimeNames = [];
    private string $anonymousTemplateDirectory = 'components';
    private bool $indexed = false;

    /** @var list<TwigComponent> */
    private array $components = [];

    /** @var array<string, TwigComponent> */
    private array $componentsByName = [];

    /** @var array<string, list<TwigComponent>> */
    private array $declarations = [];

    /** @var array<string, list<TwigComponentReference>> */
    private array $references = [];

    /** @var array<string, list<TwigComponentReference>> */
    private array $caseInsensitiveReferences = [];

    /** @var array<string, array<string, list<TwigComponentActionReference>>> */
    private array $actionReferences = [];

    /** @var array<string, list<LiveComponentEvent>> */
    private array $events = [];

    /** @var list<string> */
    private array $eventNames = [];

    /** @return list<TwigComponent> */
    public function components(): array
    {
        $this->index();

        return $this->components;
    }

    /** @return list<TwigComponent> */
    public function declarations(string $name): array
    {
        $this->index();

        return $this->declarations[$name] ?? [];
    }

    public function get(string $name): ?TwigComponent
    {
        $this->index();

        // vendor components, such as ux:icon, only exist in runtime metadata
        return $this->componentsByName[$name]
            ?? $this->runtimeComponents[$name]
            ?? (isset($this->caseInsensitiveRuntimeNames[strtolower($name)]) ? $this->caseInsensitiveRuntimeComponents[strtolower($name)] ?? null : null);
    }

    /** @return list<TwigComponentReference> */
    public function references(string $name): array
    {
        $this->index();

        return isset($this->caseInsensitiveRuntimeNames[strtolower($name)]) ? $this->caseInsensitiveReferences[strtolower($name)] ?? [] : $this->references[$name] ?? [];
    }

    /** @return list<TwigComponentActionReference> */
    public function actionReferences(string $component, string $action): array
    {
        $this->index();

        return $this->actionReferences[$component][$action] ?? [];
    }

    /** @return list<LiveComponentEvent> */
    public function events(string $name): array
    {
        $this->index();

        return $this->events[$name] ?? [];
    }

    /** @return list<string> */
    public function eventNames(): array
    {
        $this->index();

        return $this->eventNames;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * @param list<string>        $names
     * @param list<string>        $caseInsensitiveNames
     * @param list<TwigComponent> $components
     */
    public function replaceRuntime(bool $complete, array $names, string $anonymousTemplateDirectory, array $caseInsensitiveNames = [], array $components = []): void
    {
        $this->runtimeComplete = $complete;
        $this->runtimeNames = array_fill_keys($names, true);
        $this->caseInsensitiveRuntimeNames = array_fill_keys(array_map('strtolower', $caseInsensitiveNames), true);
        $this->anonymousTemplateDirectory = $anonymousTemplateDirectory;
        $this->runtimeComponents = [];
        foreach ($components as $component) {
            $this->runtimeComponents[$component->name()] = $component;
        }
        $this->caseInsensitiveRuntimeComponents = [];
        foreach ($this->runtimeComponents as $component) {
            $this->caseInsensitiveRuntimeComponents[strtolower($component->name())] ??= $component;
        }
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

    protected function factsChanged(): void
    {
        $this->indexed = false;
    }

    private function index(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->componentsByName = [];
        $this->declarations = [];
        $this->references = [];
        $this->caseInsensitiveReferences = [];
        $this->actionReferences = [];
        $this->events = [];
        $eventNames = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->components() as $component) {
                $name = $component->name();
                $this->declarations[$name][] = $component;
                $this->componentsByName[$name] = $this->merge($this->componentsByName[$name] ?? null, $component);
            }
            foreach ($facts->references() as $reference) {
                $this->references[$reference->name()][] = $reference;
                $this->caseInsensitiveReferences[strtolower($reference->name())][] = $reference;
            }
            foreach ($facts->actionReferences() as $reference) {
                $this->actionReferences[$reference->component()][$reference->action()][] = $reference;
            }
            foreach ($facts->events() as $event) {
                $this->events[$event->name()][] = $event;
                if ($event->isDeclaration()) {
                    $eventNames['s'.$event->name()] = $event->name();
                }
            }
        }

        ksort($this->componentsByName);
        $this->components = array_values($this->componentsByName);
        $this->eventNames = array_values($eventNames);
        sort($this->eventNames);
        $this->indexed = true;
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
