<?php

/*
 * Event metadata comes from compile-time container tags instead of
 * debug:event-dispatcher, because describing the dispatcher instantiates
 * every listener and applications may require external services (such as a
 * database connection) in listener constructors.
 */

function symfonyLspBridgeEventsSection(SymfonyLspBridgeContext $context): ?array
{
    $eventItems = [];
    $listeners = [];
    $complete = true;
    if (interface_exists(Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class)) {
        try {
            $aliases = symfonyLspBridgeEventAliases($context);
            $application = $context->application();
            $listenerDefinitions = symfonyLspBridgeRunJsonCommand($application, [
                'command' => 'debug:container',
                '--tag' => 'kernel.event_listener',
                '--format' => 'json',
                ...$context->commandOptions(),
            ]);
            foreach (symfonyLspBridgeContainerTagDefinitions($listenerDefinitions) as $definition) {
                foreach (symfonyLspBridgeListenerTagEntries($definition, $aliases) as $listener) {
                    $listeners[] = $listener;
                }
            }
            $subscriberDefinitions = symfonyLspBridgeRunJsonCommand($application, [
                'command' => 'debug:container',
                '--tag' => 'kernel.event_subscriber',
                '--format' => 'json',
                ...$context->commandOptions(),
            ]);
            foreach (symfonyLspBridgeContainerTagDefinitions($subscriberDefinitions) as $definition) {
                foreach (symfonyLspBridgeSubscriberEntries($definition, $aliases) as $listener) {
                    $listeners[] = $listener;
                }
            }
        } catch (Throwable) {
            $complete = false;
            $context->addError('events');
        }
    }
    $uniqueListeners = [];
    foreach ($listeners as $listener) {
        $uniqueListeners[implode("\0", [$listener['event'], $listener['class'], $listener['method'], $listener['priority']])] = $listener;
    }
    $listeners = array_values($uniqueListeners);
    $eventNames = [];
    foreach ($listeners as $listener) {
        $eventNames[$listener['event']] = true;
    }
    foreach (array_keys($eventNames) as $name) {
        $eventItems[] = [
            'name' => $name,
            'class' => class_exists($name) || interface_exists($name) ? $name : null,
        ];
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
    return symfonyLspBridgeFinalizeSection($section);
}

function symfonyLspBridgeEventAliases(SymfonyLspBridgeContext $context): array
{
    try {
        $kernel = $context->kernel();
        if (!method_exists($kernel, 'getContainer')) {
            return [];
        }
        $container = $kernel->getContainer();
        if (!is_object($container) || !method_exists($container, 'hasParameter') || !method_exists($container, 'getParameter')) {
            return [];
        }
        if (!$container->hasParameter('event_dispatcher.event_aliases')) {
            return [];
        }
        $aliases = $container->getParameter('event_dispatcher.event_aliases');
    } catch (Throwable) {
        return [];
    }

    return is_array($aliases) ? $aliases : [];
}

function symfonyLspBridgeContainerTagDefinitions(array $payload): array
{
    $definitions = [];
    foreach (is_array($payload['definitions'] ?? null) ? $payload['definitions'] : [] as $definition) {
        if (!is_array($definition) || true === ($definition['abstract'] ?? false)) {
            continue;
        }
        if (!is_string($definition['class'] ?? null) || '' === $definition['class']) {
            continue;
        }
        $definitions[] = $definition;
    }

    return $definitions;
}

function symfonyLspBridgeListenerTagEntries(array $definition, array $aliases): array
{
    $class = $definition['class'];
    $entries = [];
    $tags = is_array($definition['tags'] ?? null) ? $definition['tags'] : [];
    $subscriber = false;
    foreach ($tags as $tag) {
        if (is_array($tag) && 'kernel.event_subscriber' === ($tag['name'] ?? null)) {
            $subscriber = true;
        }
    }
    foreach ($tags as $tag) {
        if (!is_array($tag) || 'kernel.event_listener' !== ($tag['name'] ?? null)) {
            continue;
        }
        $parameters = is_array($tag['parameters'] ?? null) ? $tag['parameters'] : [];
        $event = $parameters['event'] ?? null;
        $method = is_string($parameters['method'] ?? null) && '' !== $parameters['method'] ? $parameters['method'] : null;
        $priority = is_int($parameters['priority'] ?? null) ? $parameters['priority'] : 0;
        try {
            if (!is_string($event) || '' === $event) {
                if ($subscriber) {
                    continue;
                }
                $method ??= '__invoke';
                $eventNames = symfonyLspBridgeListenerParameterEvents($class, $method);
            } else {
                $eventNames = [$event];
            }
            foreach ($eventNames as $eventName) {
                $eventName = $aliases[$eventName] ?? $eventName;
                $entries[] = [
                    'event' => $eventName,
                    'class' => $class,
                    'method' => $method ?? symfonyLspBridgeListenerDefaultMethod($class, $eventName),
                    'priority' => $priority,
                ];
            }
        } catch (Throwable) {
            continue;
        }
    }

    return $entries;
}

function symfonyLspBridgeListenerParameterEvents(string $class, string $method): array
{
    if (!class_exists($class) || !method_exists($class, $method)) {
        return [];
    }
    $reflection = new ReflectionMethod($class, $method);
    if (1 > $reflection->getNumberOfParameters()) {
        return [];
    }
    $type = $reflection->getParameters()[0]->getType();
    $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
    $names = [];
    foreach ($types as $type) {
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            continue;
        }
        $name = $type->getName();
        if ('Symfony\Contracts\EventDispatcher\Event' === $name) {
            continue;
        }
        $names[] = $name;
    }

    return $names;
}

function symfonyLspBridgeListenerDefaultMethod(string $class, string $event): string
{
    $method = 'on'.preg_replace_callback([
        '/(?<=\b|_)[a-z]/i',
        '/[^a-z0-9]/i',
    ], static fn (array $matches): string => strtoupper($matches[0]), $event);
    $method = (string) preg_replace('/[^a-z0-9]/i', '', $method);
    if (class_exists($class) && !method_exists($class, $method) && method_exists($class, '__invoke')) {
        return '__invoke';
    }

    return $method;
}

function symfonyLspBridgeSubscriberEntries(array $definition, array $aliases): array
{
    $class = $definition['class'];
    $entries = [];
    try {
        if (!class_exists($class) || !is_subclass_of($class, Symfony\Component\EventDispatcher\EventSubscriberInterface::class)) {
            return [];
        }
        foreach ($class::getSubscribedEvents() as $eventName => $parameters) {
            if (!is_string($eventName) || '' === $eventName) {
                continue;
            }
            $eventName = $aliases[$eventName] ?? $eventName;
            if (is_string($parameters)) {
                $entries[] = ['event' => $eventName, 'class' => $class, 'method' => $parameters, 'priority' => 0];
            } elseif (is_array($parameters) && is_string($parameters[0] ?? null)) {
                $priority = $parameters[1] ?? 0;
                $entries[] = ['event' => $eventName, 'class' => $class, 'method' => $parameters[0], 'priority' => is_int($priority) ? $priority : 0];
            } elseif (is_array($parameters)) {
                foreach ($parameters as $listener) {
                    if (!is_array($listener) || !is_string($listener[0] ?? null)) {
                        continue;
                    }
                    $priority = $listener[1] ?? 0;
                    $entries[] = ['event' => $eventName, 'class' => $class, 'method' => $listener[0], 'priority' => is_int($priority) ? $priority : 0];
                }
            }
        }
    } catch (Throwable) {
        return $entries;
    }

    return $entries;
}
