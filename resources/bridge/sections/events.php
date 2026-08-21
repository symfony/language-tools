<?php

function bridgeEventsSection(SymfonyLspBridgeContext $context): ?array
{
    $eventItems = [];
    $listeners = [];
    $complete = true;
    if (interface_exists(Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class)) {
        try {
            $application = $context->application();
            $events = runJsonCommand($application, [
                'command' => 'debug:event-dispatcher',
                '--format' => 'json',
                ...$context->commandOptions(),
            ]);
            foreach ($events as $name => $eventListeners) {
                if (!is_string($name)) {
                    continue;
                }
                $eventItems[] = [
                    'name' => $name,
                    'class' => class_exists($name) || interface_exists($name) ? $name : null,
                ];
                foreach (is_array($eventListeners) ? $eventListeners : [] as $listener) {
                    if (!is_array($listener) || !is_string($listener['class'] ?? null) || !is_string($listener['name'] ?? null)) {
                        continue;
                    }
                    $listeners[] = [
                        'event' => $name,
                        'class' => $listener['class'],
                        'method' => $listener['name'],
                        'priority' => is_int($listener['priority'] ?? null) ? $listener['priority'] : 0,
                    ];
                }
            }
        } catch (Throwable) {
            $complete = false;
            $context->addError('events');
        }
    }
    usort($eventItems, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);
    usort($listeners, static fn (array $left, array $right): int => [$left['event'], -$left['priority'], $left['class'], $left['method']] <=> [$right['event'], -$right['priority'], $right['class'], $right['method']]);
    $section = [
        'complete' => $complete,
        'events' => $eventItems,
        'listeners' => $listeners,
        'resources' => [],
        'warnings' => [],
    ];
    $section['generation'] = hash('sha256', json_encode($section, JSON_THROW_ON_ERROR));

    return $section;
}
