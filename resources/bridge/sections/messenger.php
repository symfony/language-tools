<?php

function bridgeMessengerSection(SymfonyLspBridgeContext $context): ?array
{
    $environment = $context->environment();
    $noDebug = !$context->debug();
    $buses = [];
    $transports = [];
    $messages = [];
    $handlers = [];
    $warnings = [];
    $complete = true;
    if (interface_exists(Symfony\Component\Messenger\MessageBusInterface::class)) {
        try {
            $kernel = $context->kernel();
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $commandOptions = [
                '--env' => $environment,
                '--no-debug' => $noDebug,
                '--no-interaction' => true,
            ];
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
                    $name = is_string($parameters['alias'] ?? null) ? $parameters['alias'] : preg_replace('/^messenger\\.transport\\./', '', $id);
                    if (is_string($name)) {
                        $transports[$name] = [
                            'name' => $name,
                            'failure' => true === ($parameters['is_failure_transport'] ?? false),
                        ];
                    }
                }
            }
            try {
                $configuration = runJsonCommand($application, [
                    'command' => 'debug:config',
                    'name' => 'framework',
                    'path' => 'messenger',
                    '--format' => 'json',
                    ...$commandOptions,
                ]);
                $defaultBus = is_string($configuration['default_bus'] ?? null) ? $configuration['default_bus'] : null;
                foreach (is_array($configuration['buses'] ?? null) ? $configuration['buses'] : [] as $name => $options) {
                    if (is_string($name)) {
                        $buses[$name] = ['name' => $name, 'default' => $name === $defaultBus];
                    }
                }
                $failureTransport = is_string($configuration['failure_transport'] ?? null) ? $configuration['failure_transport'] : null;
                foreach (is_array($configuration['transports'] ?? null) ? $configuration['transports'] : [] as $name => $options) {
                    if (is_string($name)) {
                        $transports[$name] = ['name' => $name, 'failure' => $name === $failureTransport];
                    }
                }
                foreach (is_array($configuration['routing'] ?? null) ? $configuration['routing'] : [] as $message => $routing) {
                    if (!is_string($message)) {
                        continue;
                    }
                    $senders = is_array($routing) && is_array($routing['senders'] ?? null) ? $routing['senders'] : $routing;
                    if (is_string($senders)) {
                        $senders = [$senders];
                    }
                    $messages[$message] = [
                        'class' => $message,
                        'transports' => array_values(array_filter(is_array($senders) ? $senders : [], 'is_string')),
                    ];
                }
            } catch (Throwable $error) {
                $warnings[] = 'Configuration: '.$error->getMessage();
            }
            foreach (array_keys($buses) as $bus) {
                try {
                    $locator = runJsonCommand($application, [
                        'command' => 'debug:container',
                        'name' => $bus.'.messenger.handlers_locator',
                        '--format' => 'json',
                        '--show-hidden' => true,
                        ...$commandOptions,
                    ]);
                    $mapping = is_array($locator['arguments'][0] ?? null) ? $locator['arguments'][0] : [];
                    foreach ($mapping as $message => $descriptorReferences) {
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
                            $handlers[] = [
                                'message' => $message,
                                'bus' => $bus,
                                'service' => $service,
                                'class' => is_string($handlerDefinition['class'] ?? null) ? $handlerDefinition['class'] : $service,
                                'method' => is_string($options['method'] ?? null) ? $options['method'] : '__invoke',
                                'priority' => is_int($options['priority'] ?? null) ? $options['priority'] : 0,
                                'fromTransport' => is_string($options['from_transport'] ?? null) ? $options['from_transport'] : null,
                            ];
                        }
                    }
                } catch (Throwable $error) {
                    $warnings[] = sprintf('%s: %s', $bus, $error->getMessage());
                }
            }
            if ([] === $handlers) {
                foreach ($definitions as $service => $definition) {
                    if (!is_string($service) || !is_array($definition)) {
                        continue;
                    }
                    foreach (definitionTagParameters($definition, 'messenger.message_handler') as $options) {
                        $class = is_string($definition['class'] ?? null) ? $definition['class'] : $service;
                        $method = is_string($options['method'] ?? null) && '' !== $options['method'] ? $options['method'] : '__invoke';
                        $handledMessages = is_string($options['handles'] ?? null) && '' !== $options['handles'] ? [$options['handles']] : inferHandlerMessages($class, $method);
                        $handlerBuses = is_string($options['bus'] ?? null) && '' !== $options['bus'] ? [$options['bus']] : array_keys($buses);
                        foreach ($handledMessages as $message) {
                            $messages[$message] ??= ['class' => $message, 'transports' => []];
                            foreach ($handlerBuses as $bus) {
                                $handlers[] = [
                                    'message' => $message,
                                    'bus' => $bus,
                                    'service' => $service,
                                    'class' => $class,
                                    'method' => $method,
                                    'priority' => is_int($options['priority'] ?? null) ? $options['priority'] : 0,
                                    'fromTransport' => is_string($options['from_transport'] ?? null) && '' !== $options['from_transport'] ? $options['from_transport'] : null,
                                ];
                            }
                        }
                    }
                }
            }
            try {
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
                    $messages[$message]['transports'] = array_values(array_filter(is_array($senderNames) ? $senderNames : [], 'is_string'));
                }
            } catch (Throwable $error) {
                $warnings[] = 'Routing: '.$error->getMessage();
            }
        } catch (Throwable $error) {
            $complete = false;
            $context->addError('messenger', $error->getMessage());
        }
    }
    ksort($buses);
    ksort($transports);
    ksort($messages);
    usort($handlers, static fn (array $left, array $right): int => [$left['message'], $left['bus'], $left['class'], $left['method']] <=> [$right['message'], $right['bus'], $right['class'], $right['method']]);
    sort($warnings);
    $section = [
        'complete' => $complete,
        'buses' => array_values($buses),
        'transports' => array_values($transports),
        'messages' => array_values($messages),
        'handlers' => $handlers,
        'resources' => [],
        'warnings' => $warnings,
    ];
    $section['generation'] = hash('sha256', json_encode($section, JSON_THROW_ON_ERROR));

    return $section;
}
