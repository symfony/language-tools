<?php

namespace Symfony\Lsp\Feature\Event;

final class EventIndex
{
    /** @var array<string, Event> */
    private array $events = [];
    /** @var list<EventListener> */
    private array $listeners = [];
    private bool $complete = false;

    /**
     * @param list<Event>         $events
     * @param list<EventListener> $listeners
     */
    public function replace(array $events, array $listeners, bool $complete): void
    {
        $this->events = [];
        foreach ($events as $event) {
            $this->events[$event->name()] = $event;
        }
        ksort($this->events);
        $this->listeners = $listeners;
        $this->complete = $complete;
    }

    /** @return list<Event> */
    public function events(): array
    {
        return array_values($this->events);
    }

    public function event(string $name): ?Event
    {
        return $this->events[ltrim($name, '\\')] ?? null;
    }

    /** @return list<EventListener> */
    public function listenersForEvent(string $name): array
    {
        return array_values(array_filter($this->listeners, static fn (EventListener $listener): bool => $listener->event() === ltrim($name, '\\')));
    }

    /** @return list<EventListener> */
    public function listenersByClass(string $className): array
    {
        return array_values(array_filter($this->listeners, static fn (EventListener $listener): bool => $listener->className() === ltrim($className, '\\')));
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
