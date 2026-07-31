<?php

function bridgeEventsSection(SymfonyLspBridgeContext $context): ?array
{
    $environment = $context->environment();
    $noDebug = !$context->debug();
    $eventItems = [];
    $listeners = [];
    $complete = true;
    if (interface_exists(Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class)) {
        try {
            $kernel = $context->kernel();
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $events = runJsonCommand($application, [
                'command' => 'debug:event-dispatcher',
                '--format' => 'json',
                '--env' => $environment,
                '--no-debug' => $noDebug,
                '--no-interaction' => true,
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
        } catch (Throwable $error) {
            $complete = false;
            $context->addError('events', $error->getMessage());
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
