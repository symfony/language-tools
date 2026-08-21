<?php

function bridgeMessengerSection(SymfonyLspBridgeContext $context): ?array
{
    $buses = [];
    $transports = [];
    $messages = [];
    $handlers = [];
    $warnings = [];
    $complete = true;
    if (interface_exists(Symfony\Component\Messenger\MessageBusInterface::class)) {
        try {
            $application = $context->application();
            $commandOptions = $context->commandOptions();
            $definitions = bridgeMessengerDefinitions($application, $commandOptions);
            [$buses, $transports] = bridgeMessengerTaggedTopology($definitions);
            try {
                [$configuredBuses, $configuredTransports, $configuredMessages] = bridgeMessengerConfiguration($application, $commandOptions);
                $buses = array_replace($buses, $configuredBuses);
                $transports = array_replace($transports, $configuredTransports);
                $messages = array_replace($messages, $configuredMessages);
            } catch (Throwable) {
                $warnings[] = 'The messenger configuration is unavailable.';
            }
            [$handlers, $locatorMessages, $locatorWarnings] = bridgeMessengerLocatorHandlers($application, $commandOptions, $definitions, array_keys($buses));
            $messages = bridgeMessengerMergeMessages($messages, $locatorMessages);
            array_push($warnings, ...$locatorWarnings);
            if ([] === $handlers) {
                [$handlers, $taggedMessages] = bridgeMessengerTaggedHandlers($definitions, array_keys($buses));
                $messages = bridgeMessengerMergeMessages($messages, $taggedMessages);
            }
            try {
                $messages = bridgeMessengerSenderRouting($application, $commandOptions, $messages);
            } catch (Throwable) {
                $warnings[] = 'The messenger routing is unavailable.';
            }
        } catch (Throwable) {
            $complete = false;
            $context->addError('messenger');
        }
    }
    ksort($buses);
    ksort($transports);
    ksort($messages);
    sort($warnings);

    return finalizeBridgeSection([
        'complete' => $complete,
        'buses' => array_values($buses),
        'transports' => array_values($transports),
        'messages' => array_values($messages),
        'handlers' => bridgeMessengerSortHandlers($handlers),
        'resources' => [],
        'warnings' => $warnings,
    ]);
}

function bridgeMessengerDefinitions(object $application, array $commandOptions): array
{
    $container = runJsonCommand($application, [
        'command' => 'debug:container',
        '--format' => 'json',
        '--show-hidden' => true,
        ...$commandOptions,
    ]);
    $definitions = is_array($container['definitions'] ?? null) ? $container['definitions'] : [];
    foreach (['messenger.bus', 'messenger.receiver', 'messenger.message_handler'] as $tagName) {
        $tagged = runJsonCommand($application, [
            'command' => 'debug:container',
            '--tag' => $tagName,
            '--format' => 'json',
            ...$commandOptions,
        ]);
        foreach (is_array($tagged['definitions'] ?? null) ? $tagged['definitions'] : [] as $id => $definition) {
            if (is_string($id) && is_array($definition)) {
                $definitions[$id] = $definition;
            }
        }
    }

    return $definitions;
}

function bridgeMessengerTaggedTopology(array $definitions): array
{
    $buses = [];
    $transports = [];
    foreach ($definitions as $id => $definition) {
        if (!is_string($id) || !is_array($definition)) {
            continue;
        }
        foreach (definitionTagParameters($definition, 'messenger.bus') as $parameters) {
            $buses[$id] = [
                'name' => $id,
                'default' => in_array('messenger.default_bus', is_array($definition['usages'] ?? null) ? $definition['usages'] : [], true),
            ];
        }
        foreach (definitionTagParameters($definition, 'messenger.receiver') as $parameters) {
            $name = is_string($parameters['alias'] ?? null) ? $parameters['alias'] : preg_replace('/^messenger\.transport\./', '', $id);
            if (is_string($name)) {
                $transports[$name] = [
                    'name' => $name,
                    'failure' => true === ($parameters['is_failure_transport'] ?? false),
                ];
            }
        }
    }

    return [$buses, $transports];
}

function bridgeMessengerConfiguration(object $application, array $commandOptions): array
{
    $configuration = runJsonCommand($application, [
        'command' => 'debug:config',
        'name' => 'framework',
        'path' => 'messenger',
        '--format' => 'json',
        ...$commandOptions,
    ]);
    $buses = [];
    $defaultBus = is_string($configuration['default_bus'] ?? null) ? $configuration['default_bus'] : null;
    foreach (is_array($configuration['buses'] ?? null) ? $configuration['buses'] : [] as $name => $options) {
        if (is_string($name)) {
            $buses[$name] = ['name' => $name, 'default' => $name === $defaultBus];
        }
    }
    $transports = [];
    $failureTransport = is_string($configuration['failure_transport'] ?? null) ? $configuration['failure_transport'] : null;
    foreach (is_array($configuration['transports'] ?? null) ? $configuration['transports'] : [] as $name => $options) {
        if (is_string($name)) {
            $transports[$name] = ['name' => $name, 'failure' => $name === $failureTransport];
        }
    }
    $messages = [];
    foreach (is_array($configuration['routing'] ?? null) ? $configuration['routing'] : [] as $message => $routing) {
        if (!is_string($message)) {
            continue;
        }
        $senders = is_array($routing) && is_array($routing['senders'] ?? null) ? $routing['senders'] : $routing;
        $messages[$message] = ['class' => $message, 'transports' => bridgeMessengerStringList($senders)];
    }

    return [$buses, $transports, $messages];
}

function bridgeMessengerLocatorHandlers(object $application, array $commandOptions, array $definitions, array $buses): array
{
    $handlers = [];
    $messages = [];
    $warnings = [];
    foreach ($buses as $bus) {
        try {
            $locator = runJsonCommand($application, [
                'command' => 'debug:container',
                'name' => $bus.'.messenger.handlers_locator',
                '--format' => 'json',
                '--show-hidden' => true,
                ...$commandOptions,
            ]);
            foreach (is_array($locator['arguments'][0] ?? null) ? $locator['arguments'][0] : [] as $message => $descriptorReferences) {
                if (!is_string($message)) {
                    continue;
                }
                $messages[$message] ??= ['class' => $message, 'transports' => []];
                foreach (is_array($descriptorReferences) ? $descriptorReferences : [] as $reference) {
                    $descriptorId = is_array($reference) && is_string($reference['id'] ?? null) ? $reference['id'] : null;
                    $descriptor = null !== $descriptorId && is_array($definitions[$descriptorId] ?? null) ? $definitions[$descriptorId] : null;
                    if (null === $descriptor) {
                        continue;
                    }
                    $serviceReference = $descriptor['arguments'][0] ?? null;
                    $service = is_array($serviceReference) && is_string($serviceReference['id'] ?? null) ? $serviceReference['id'] : null;
                    if (null === $service) {
                        continue;
                    }
                    $handlerDefinition = is_array($definitions[$service] ?? null) ? $definitions[$service] : [];
                    $options = is_array($descriptor['arguments'][1] ?? null) ? $descriptor['arguments'][1] : [];
                    $handlers[] = bridgeMessengerHandler(
                        $message,
                        $bus,
                        $service,
                        is_string($handlerDefinition['class'] ?? null) ? $handlerDefinition['class'] : $service,
                        is_string($options['method'] ?? null) ? $options['method'] : '__invoke',
                        is_int($options['priority'] ?? null) ? $options['priority'] : 0,
                        is_string($options['from_transport'] ?? null) ? $options['from_transport'] : null,
                    );
                }
            }
        } catch (Throwable) {
            $warnings[] = sprintf('The %s handlers locator is unavailable.', $bus);
        }
    }

    return [$handlers, $messages, $warnings];
}

function bridgeMessengerTaggedHandlers(array $definitions, array $buses): array
{
    $handlers = [];
    $messages = [];
    foreach ($definitions as $service => $definition) {
        if (!is_string($service) || !is_array($definition)) {
            continue;
        }
        foreach (definitionTagParameters($definition, 'messenger.message_handler') as $options) {
            $class = is_string($definition['class'] ?? null) ? $definition['class'] : $service;
            $method = is_string($options['method'] ?? null) && '' !== $options['method'] ? $options['method'] : '__invoke';
            $handledMessages = is_string($options['handles'] ?? null) && '' !== $options['handles'] ? [$options['handles']] : inferHandlerMessages($class, $method);
            $handlerBuses = is_string($options['bus'] ?? null) && '' !== $options['bus'] ? [$options['bus']] : $buses;
            foreach ($handledMessages as $message) {
                $messages[$message] ??= ['class' => $message, 'transports' => []];
                foreach ($handlerBuses as $bus) {
                    $handlers[] = bridgeMessengerHandler(
                        $message,
                        $bus,
                        $service,
                        $class,
                        $method,
                        is_int($options['priority'] ?? null) ? $options['priority'] : 0,
                        is_string($options['from_transport'] ?? null) && '' !== $options['from_transport'] ? $options['from_transport'] : null,
                    );
                }
            }
        }
    }

    return [$handlers, $messages];
}

function bridgeMessengerSenderRouting(object $application, array $commandOptions, array $messages): array
{
    $senders = runJsonCommand($application, [
        'command' => 'debug:container',
        'name' => 'messenger.senders_locator',
        '--format' => 'json',
        '--show-hidden' => true,
        ...$commandOptions,
    ]);
    foreach (is_array($senders['arguments'][0] ?? null) ? $senders['arguments'][0] : [] as $message => $senderNames) {
        if (!is_string($message)) {
            continue;
        }
        $messages[$message] ??= ['class' => $message, 'transports' => []];
        $messages[$message]['transports'] = bridgeMessengerStringList($senderNames);
    }

    return $messages;
}

function bridgeMessengerHandler(string $message, string $bus, string $service, string $class, string $method, int $priority, ?string $fromTransport): array
{
    return [
        'message' => $message,
        'bus' => $bus,
        'service' => $service,
        'class' => $class,
        'method' => $method,
        'priority' => $priority,
        'fromTransport' => $fromTransport,
    ];
}

function bridgeMessengerMergeMessages(array $messages, array $additional): array
{
    foreach ($additional as $class => $message) {
        $messages[$class] ??= $message;
    }

    return $messages;
}

function bridgeMessengerStringList(mixed $values): array
{
    $values = is_string($values) ? [$values] : $values;
    $strings = [];
    foreach (is_array($values) ? $values : [] as $value) {
        if (is_string($value)) {
            $strings[] = $value;
        }
    }

    return $strings;
}

function bridgeMessengerSortHandlers(array $handlers): array
{
    $sorted = [];
    foreach ($handlers as $offset => $handler) {
        $key = implode('|', [$handler['message'], $handler['bus'], $handler['class'], $handler['method'], sprintf('%08d', $offset)]);
        $sorted[$key] = $handler;
    }
    ksort($sorted);

    return array_values($sorted);
}
