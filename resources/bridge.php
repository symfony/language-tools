<?php

if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, "Symfony LSP's project bridge requires PHP 8.1 or newer.\n");
    exit(1);
}

$options = getopt('', ['project:', 'environment::', 'debug::', 'sections::']);
$project = $options['project'] ?? null;
if (!is_string($project) || '' === $project) {
    fwrite(STDERR, "The --project option is required.\n");
    exit(1);
}

$autoload = rtrim($project, '/\\').'/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "The project Composer autoloader was not found.\n");
    exit(1);
}

require $autoload;

if (!class_exists(Composer\InstalledVersions::class)) {
    fwrite(STDERR, "Composer runtime metadata is unavailable.\n");
    exit(1);
}

$version = Composer\InstalledVersions::getPrettyVersion('symfony/framework-bundle');
if (!is_string($version)) {
    fwrite(STDERR, "symfony/framework-bundle is not installed.\n");
    exit(1);
}

if (!preg_match('/^(?:v)?(6\.4|7\.4|8\.0|8\.1)(?:\.|$)/', $version, $matches)) {
    fwrite(STDERR, sprintf("Symfony FrameworkBundle %s is not supported.\n", $version));
    exit(1);
}

$environment = $options['environment'] ?? 'dev';
$debug = $options['debug'] ?? '1';
$requestedSections = $options['sections'] ?? '';
$requestedSections = is_string($requestedSections)
    ? array_values(array_filter(explode(',', $requestedSections)))
    : [];

if (class_exists(Symfony\Component\Runtime\SymfonyRuntime::class)) {
    new Symfony\Component\Runtime\SymfonyRuntime([
        'project_dir' => $project,
        'env' => is_string($environment) ? $environment : 'dev',
        'debug' => !in_array($debug, ['0', 'false'], true),
    ]);
} elseif (class_exists(Symfony\Component\Dotenv\Dotenv::class)) {
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv(
        rtrim($project, '/\\').'/.env',
        is_string($environment) ? $environment : 'dev',
    );
}

$sections = [];
$errors = [];
$bridgeKernel = null;
$bridgeKernelError = null;
if (in_array('routes', $requestedSections, true)) {
    if (!class_exists(Symfony\Component\Console\Input\ArrayInput::class)
        || !class_exists(Symfony\Component\Console\Output\BufferedOutput::class)
    ) {
        $errors[] = ['section' => 'routes', 'message' => 'Symfony Console is unavailable.'];
    } else {
        $kernel = null;
        try {
            $kernelClass = 'App\\Kernel';
            if (!class_exists($kernelClass)) {
                throw new RuntimeException('The default App\\Kernel class was not found.');
            }

            $kernel = bridgeKernel(
                $kernelClass,
                is_string($environment) ? $environment : 'dev',
                !in_array($debug, ['0', 'false'], true),
            );
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $input = new Symfony\Component\Console\Input\ArrayInput([
                'command' => 'debug:router',
                '--format' => 'json',
                '--show-aliases' => true,
                '--env' => is_string($environment) ? $environment : 'dev',
                '--no-debug' => in_array($debug, ['0', 'false'], true),
                '--no-interaction' => true,
            ]);
            $output = new Symfony\Component\Console\Output\BufferedOutput();
            $exitCode = $application->run($input, $output);
            if (0 !== $exitCode) {
                throw new RuntimeException(sprintf('debug:router exited with status %d.', $exitCode));
            }

            $routes = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($routes)) {
                throw new RuntimeException('debug:router did not return a JSON object or array.');
            }

            $items = [];
            foreach ($routes as $name => $route) {
                if (!is_string($name) || !is_array($route)) {
                    continue;
                }

                $methods = is_array($route['methods'] ?? null)
                    ? array_values($route['methods'])
                    : splitDebugValues($route['method'] ?? null);
                $schemes = is_array($route['schemes'] ?? null)
                    ? array_values($route['schemes'])
                    : splitDebugValues($route['scheme'] ?? null);
                $host = is_string($route['host'] ?? null) && !in_array($route['host'], ['', 'ANY'], true)
                    ? $route['host']
                    : null;
                $defaults = is_array($route['defaults'] ?? null) ? $route['defaults'] : [];
                $requirements = [];
                foreach (is_array($route['requirements'] ?? null) ? $route['requirements'] : [] as $key => $value) {
                    if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                        $requirements[$key] = (string) $value;
                    }
                }
                $alias = is_string($route['alias'] ?? null)
                    ? $route['alias']
                    : (is_string($route['aliasFor'] ?? null) ? $route['aliasFor'] : null);
                $items[] = [
                    'name' => $name,
                    'path' => is_string($route['path'] ?? null) ? $route['path'] : null,
                    'methods' => $methods,
                    'schemes' => $schemes,
                    'host' => $host,
                    'controller' => is_string($defaults['_controller'] ?? null)
                        ? $defaults['_controller']
                        : null,
                    'defaults' => array_values(array_filter(
                        array_keys($defaults),
                        static fn (mixed $key): bool => is_string($key),
                    )),
                    'requirements' => $requirements,
                    'alias' => $alias,
                ];
            }

            usort($items, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
            $sections['routes'] = [
                'complete' => true,
                'generation' => hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
                'items' => $items,
                'resources' => [],
                'warnings' => [],
            ];
        } catch (Throwable $error) {
            $errors[] = ['section' => 'routes', 'message' => $error->getMessage()];
        }
    }
}

if (in_array('container', $requestedSections, true)) {
    if (!class_exists(Symfony\Component\Console\Input\ArrayInput::class)
        || !class_exists(Symfony\Component\Console\Output\BufferedOutput::class)
    ) {
        $errors[] = ['section' => 'container', 'message' => 'Symfony Console is unavailable.'];
    } else {
        $kernel = null;
        try {
            $kernelClass = 'App\\Kernel';
            if (!class_exists($kernelClass)) {
                throw new RuntimeException('The default App\\Kernel class was not found.');
            }

            $kernel = bridgeKernel(
                $kernelClass,
                is_string($environment) ? $environment : 'dev',
                !in_array($debug, ['0', 'false'], true),
            );
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $commandOptions = [
                '--env' => is_string($environment) ? $environment : 'dev',
                '--no-debug' => in_array($debug, ['0', 'false'], true),
                '--no-interaction' => true,
            ];
            $container = runJsonCommand($application, [
                'command' => 'debug:container',
                '--format' => 'json',
                '--show-hidden' => true,
                ...$commandOptions,
            ]);
            $types = runJsonCommand($application, [
                'command' => 'debug:container',
                '--types' => true,
                '--format' => 'json',
                ...$commandOptions,
            ]);
            $parameterItems = normalizeParameters(runJsonCommand($application, [
                'command' => 'debug:container',
                '--parameters' => true,
                '--format' => 'json',
                ...$commandOptions,
            ]));

            $items = normalizeServices($container, $types);
            $safeContainer = [
                'complete' => true,
                'items' => $items,
                'parameters' => $parameterItems,
                'resources' => [],
                'warnings' => [],
            ];
            $safeContainer['generation'] = hash('sha256', json_encode($safeContainer, JSON_THROW_ON_ERROR));
            $sections['container'] = $safeContainer;
        } catch (Throwable $error) {
            $errors[] = ['section' => 'container', 'message' => $error->getMessage()];
        }
    }
}

if (in_array('twig', $requestedSections, true)) {
    $paths = [];
    if (class_exists(Twig\Environment::class)) {
        $kernel = null;
        try {
            $kernelClass = 'App\\Kernel';
            if (!class_exists($kernelClass)) {
                throw new RuntimeException('The default App\\Kernel class was not found.');
            }
            $kernel = bridgeKernel(
                $kernelClass,
                is_string($environment) ? $environment : 'dev',
                !in_array($debug, ['0', 'false'], true),
            );
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $twig = runJsonCommand($application, [
                'command' => 'debug:twig',
                '--format' => 'json',
                '--env' => is_string($environment) ? $environment : 'dev',
                '--no-debug' => in_array($debug, ['0', 'false'], true),
                '--no-interaction' => true,
            ]);
            foreach (is_array($twig['loader_paths'] ?? null) ? $twig['loader_paths'] : [] as $namespace => $loaderPaths) {
                foreach (is_array($loaderPaths) ? $loaderPaths : [] as $path) {
                    if (is_string($namespace) && is_string($path)) {
                        $paths[] = ['namespace' => $namespace, 'path' => $path];
                    }
                }
            }
        } catch (Throwable $error) {
            $errors[] = ['section' => 'twig', 'message' => $error->getMessage()];
        }
    }
    usort($paths, static fn (array $a, array $b): int => [$a['namespace'], $a['path']] <=> [$b['namespace'], $b['path']]);
    $sections['twig'] = [
        'complete' => true,
        'generation' => hash('sha256', json_encode($paths, JSON_THROW_ON_ERROR)),
        'paths' => $paths,
        'resources' => [],
        'warnings' => [],
    ];
}

if (in_array('translations', $requestedSections, true)) {
    $items = [];
    if (interface_exists(Symfony\Component\Translation\TranslatorBagInterface::class)) {
        $kernel = null;
        try {
            $kernelClass = 'App\\Kernel';
            if (!class_exists($kernelClass)) {
                throw new RuntimeException('The default App\\Kernel class was not found.');
            }
            $kernel = bridgeKernel($kernelClass, is_string($environment) ? $environment : 'dev', !in_array($debug, ['0', 'false'], true));
            $container = $kernel->getContainer();
            if ($container->has('translator')) {
                $translator = $container->get('translator');
                if ($translator instanceof Symfony\Component\Translation\TranslatorBagInterface) {
                    $locales = method_exists($translator, 'getLocale') ? [$translator->getLocale()] : [];
                    if (method_exists($translator, 'getFallbackLocales')) {
                        array_push($locales, ...$translator->getFallbackLocales());
                    }
                    if ($container->hasParameter('kernel.enabled_locales')) {
                        $enabledLocales = $container->getParameter('kernel.enabled_locales');
                        if (is_array($enabledLocales)) {
                            array_push($locales, ...array_filter($enabledLocales, 'is_string'));
                        }
                    }
                    foreach (array_values(array_unique(array_filter($locales, 'is_string'))) as $locale) {
                        foreach ($translator->getCatalogue($locale)->all() as $domain => $messages) {
                            foreach (is_array($messages) ? $messages : [] as $key => $message) {
                                if (is_string($domain) && is_string($key) && is_string($message)) {
                                    $items[] = ['key' => $key, 'domain' => $domain, 'locale' => $locale, 'message' => $message];
                                }
                            }
                        }
                    }
                }
            }
        } catch (Throwable $error) {
            $errors[] = ['section' => 'translations', 'message' => $error->getMessage()];
        }
    }
    usort($items, static fn (array $a, array $b): int => [$a['domain'], $a['key'], $a['locale']] <=> [$b['domain'], $b['key'], $b['locale']]);
    $sections['translations'] = [
        'complete' => true,
        'generation' => hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
        'items' => $items,
        'resources' => [],
        'warnings' => [],
    ];
}

if (in_array('messenger', $requestedSections, true)) {
    $buses = [];
    $transports = [];
    $messages = [];
    $handlers = [];
    $warnings = [];
    $complete = true;
    if (interface_exists(Symfony\Component\Messenger\MessageBusInterface::class)) {
        try {
            $kernelClass = 'App\\Kernel';
            if (!class_exists($kernelClass)) {
                throw new RuntimeException('The default App\\Kernel class was not found.');
            }
            $kernel = bridgeKernel($kernelClass, is_string($environment) ? $environment : 'dev', !in_array($debug, ['0', 'false'], true));
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $commandOptions = [
                '--env' => is_string($environment) ? $environment : 'dev',
                '--no-debug' => in_array($debug, ['0', 'false'], true),
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
            $errors[] = ['section' => 'messenger', 'message' => $error->getMessage()];
        }
    }
    ksort($buses);
    ksort($transports);
    ksort($messages);
    usort($handlers, static fn (array $left, array $right): int => [$left['message'], $left['bus'], $left['class'], $left['method']] <=> [$right['message'], $right['bus'], $right['class'], $right['method']]);
    sort($warnings);
    $messenger = [
        'complete' => $complete,
        'buses' => array_values($buses),
        'transports' => array_values($transports),
        'messages' => array_values($messages),
        'handlers' => $handlers,
        'resources' => [],
        'warnings' => $warnings,
    ];
    $messenger['generation'] = hash('sha256', json_encode($messenger, JSON_THROW_ON_ERROR));
    $sections['messenger'] = $messenger;
}

if (in_array('events', $requestedSections, true)) {
    $eventItems = [];
    $listeners = [];
    $complete = true;
    if (interface_exists(Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class)) {
        try {
            $kernelClass = 'App\\Kernel';
            if (!class_exists($kernelClass)) {
                throw new RuntimeException('The default App\\Kernel class was not found.');
            }
            $kernel = bridgeKernel($kernelClass, is_string($environment) ? $environment : 'dev', !in_array($debug, ['0', 'false'], true));
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $events = runJsonCommand($application, [
                'command' => 'debug:event-dispatcher',
                '--format' => 'json',
                '--env' => is_string($environment) ? $environment : 'dev',
                '--no-debug' => in_array($debug, ['0', 'false'], true),
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
            $errors[] = ['section' => 'events', 'message' => $error->getMessage()];
        }
    }
    usort($eventItems, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);
    usort($listeners, static fn (array $left, array $right): int => [$left['event'], -$left['priority'], $left['class'], $left['method']] <=> [$right['event'], -$right['priority'], $right['class'], $right['method']]);
    $events = [
        'complete' => $complete,
        'events' => $eventItems,
        'listeners' => $listeners,
        'resources' => [],
        'warnings' => [],
    ];
    $events['generation'] = hash('sha256', json_encode($events, JSON_THROW_ON_ERROR));
    $sections['events'] = $events;
}

if (in_array('configuration', $requestedSections, true)) {
    $bundles = [];
    $warnings = [];
    $complete = true;
    $kernel = null;
    try {
        $kernelClass = 'App\\Kernel';
        if (!class_exists($kernelClass)) {
            throw new RuntimeException('The default App\\Kernel class was not found.');
        }
        $kernel = bridgeKernel($kernelClass, is_string($environment) ? $environment : 'dev', !in_array($debug, ['0', 'false'], true));
        $builder = new Symfony\Component\DependencyInjection\ContainerBuilder();
        $builder->setParameter('kernel.environment', is_string($environment) ? $environment : 'dev');
        $builder->setParameter('kernel.debug', !in_array($debug, ['0', 'false'], true));
        $builder->setParameter('kernel.project_dir', realpath($project) ?: $project);
        $builder->setParameter('kernel.bundles', array_map(static fn (object $item): string => $item::class, $kernel->getBundles()));
        if (method_exists($kernel, 'getContainer')) {
            $runtimeContainer = $kernel->getContainer();
            foreach (['kernel.bundles_metadata', 'kernel.build_dir', 'kernel.cache_dir', 'kernel.charset', 'kernel.container_class', 'kernel.logs_dir', 'kernel.runtime_environment'] as $parameterName) {
                if ($runtimeContainer->hasParameter($parameterName)) {
                    $builder->setParameter($parameterName, $runtimeContainer->getParameter($parameterName));
                }
            }
        }
        foreach ($kernel->getBundles() as $bundle) {
            $extension = $bundle->getContainerExtension();
            if (null !== $extension) {
                $builder->registerExtension($extension);
            }
        }
        foreach ($kernel->getBundles() as $bundle) {
            try {
                $extension = $bundle->getContainerExtension();
                if (null === $extension || !method_exists($extension, 'getConfiguration')) {
                    continue;
                }
                $configuration = $extension->getConfiguration([], $builder);
                if (null === $configuration) {
                    continue;
                }
                $tree = $configuration->getConfigTreeBuilder()->buildTree();
                $alias = method_exists($extension, 'getAlias') ? $extension->getAlias() : $tree->getName();
                $bundles[] = [
                    'alias' => (string) $alias,
                    'class' => $bundle::class,
                    'tree' => normalizeConfigNode($tree),
                ];
            } catch (Throwable $error) {
                $warnings[] = sprintf('%s: %s', $bundle::class, $error->getMessage());
            }
        }
    } catch (Throwable $error) {
        $complete = false;
        $errors[] = ['section' => 'configuration', 'message' => $error->getMessage()];
    }
    usort($bundles, static fn (array $left, array $right): int => $left['alias'] <=> $right['alias']);
    sort($warnings);
    $resources = [];
    $configDir = rtrim($project, '/\\').'/config';
    if (is_dir($configDir)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configDir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['php', 'xml', 'yaml', 'yml'], true)) {
                $resources[] = $file->getPathname();
            }
        }
    }
    sort($resources);
    $sections['configuration'] = [
        'complete' => $complete,
        'generation' => hash('sha256', json_encode($bundles, JSON_THROW_ON_ERROR)),
        'bundles' => $bundles,
        'resources' => $resources,
        'warnings' => $warnings,
    ];
}

if (in_array('environment', $requestedSections, true)) {
    $processors = [];
    $complete = true;
    if (interface_exists(Symfony\Component\DependencyInjection\EnvVarProcessorInterface::class)) {
        $classes = class_exists(Symfony\Component\DependencyInjection\EnvVarProcessor::class)
            ? [Symfony\Component\DependencyInjection\EnvVarProcessor::class]
            : [];
        $kernel = null;
        try {
            $kernelClass = 'App\\Kernel';
            if (!class_exists($kernelClass)) {
                throw new RuntimeException('The default App\\Kernel class was not found.');
            }
            $kernel = bridgeKernel($kernelClass, is_string($environment) ? $environment : 'dev', !in_array($debug, ['0', 'false'], true));
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $application->setAutoExit(false);
            $tagged = runJsonCommand($application, [
                'command' => 'debug:container',
                '--tag' => 'container.env_var_processor',
                '--format' => 'json',
                '--env' => is_string($environment) ? $environment : 'dev',
                '--no-debug' => in_array($debug, ['0', 'false'], true),
                '--no-interaction' => true,
            ]);
            foreach (is_array($tagged['definitions'] ?? null) ? $tagged['definitions'] : [] as $definition) {
                if (is_array($definition) && is_string($definition['class'] ?? null)) {
                    $classes[] = $definition['class'];
                }
            }
        } catch (Throwable $error) {
            $complete = false;
            $errors[] = ['section' => 'environment', 'message' => $error->getMessage()];
        }
        foreach (array_values(array_unique($classes)) as $class) {
            if (is_a($class, Symfony\Component\DependencyInjection\EnvVarProcessorInterface::class, true)) {
                foreach ($class::getProvidedTypes() as $name => $type) {
                    if (is_string($name) && is_string($type)) {
                        $processors[$name] = $type;
                    }
                }
            }
        }
    }
    ksort($processors);
    $processorItems = [];
    foreach ($processors as $name => $type) {
        $processorItems[] = ['name' => $name, 'type' => $type];
    }
    $sections['environment'] = [
        'complete' => $complete,
        'generation' => hash('sha256', json_encode($processorItems, JSON_THROW_ON_ERROR)),
        'processors' => $processorItems,
        'resources' => [],
        'warnings' => [],
    ];
}

try {
    shutdownBridgeKernel();
} catch (Throwable $error) {
    $errors[] = ['section' => 'runtime', 'message' => $error->getMessage()];
}

$result = [
    'schemaVersion' => 1,
    'generation' => hash('sha256', json_encode($sections, JSON_THROW_ON_ERROR)),
    'project' => [
        'root' => realpath($project) ?: $project,
        'symfonyVersion' => $version,
        'symfonyBranch' => $matches[1],
        'phpVersion' => PHP_VERSION,
        'environment' => is_string($environment) ? $environment : 'dev',
        'debug' => !in_array($debug, ['0', 'false'], true),
    ],
    'sections' => $sections,
    'errors' => $errors,
];

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");

function bridgeKernel(string $kernelClass, string $environment, bool $debug): object
{
    global $bridgeKernel, $bridgeKernelError;

    if ($bridgeKernelError instanceof Throwable) {
        throw $bridgeKernelError;
    }
    if (is_object($bridgeKernel)) {
        return $bridgeKernel;
    }
    try {
        $kernel = new $kernelClass($environment, $debug);
        if (method_exists($kernel, 'boot')) {
            $kernel->boot();
        }
        $bridgeKernel = $kernel;

        return $kernel;
    } catch (Throwable $error) {
        $bridgeKernelError = $error;
        throw $error;
    }
}

function shutdownBridgeKernel(): void
{
    global $bridgeKernel;

    if (is_object($bridgeKernel) && method_exists($bridgeKernel, 'shutdown')) {
        $bridgeKernel->shutdown();
    }
}

function runJsonCommand(object $application, array $arguments): array
{
    static $cache = [];

    $cacheKey = hash('sha256', serialize($arguments));
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    $output = new Symfony\Component\Console\Output\BufferedOutput();
    $exitCode = $application->run(new Symfony\Component\Console\Input\ArrayInput($arguments), $output);
    if (0 !== $exitCode) {
        throw new RuntimeException(sprintf('%s exited with status %d.', $arguments['command'], $exitCode));
    }

    $result = json_decode(firstJsonDocument($output->fetch()), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) {
        throw new RuntimeException(sprintf('%s did not return a JSON object or array.', $arguments['command']));
    }

    return $cache[$cacheKey] = $result;
}

function firstJsonDocument(string $output): string
{
    $object = strpos($output, '{');
    $array = strpos($output, '[');
    $start = false === $object ? $array : (false === $array ? $object : min($object, $array));
    if (false === $start) {
        return $output;
    }
    $depth = 0;
    $quoted = false;
    $escaped = false;
    for ($index = $start, $length = strlen($output); $index < $length; ++$index) {
        $character = $output[$index];
        if ($quoted) {
            if ($escaped) {
                $escaped = false;
            } elseif ('\\' === $character) {
                $escaped = true;
            } elseif ('"' === $character) {
                $quoted = false;
            }
            continue;
        }
        if ('"' === $character) {
            $quoted = true;
        } elseif ('{' === $character || '[' === $character) {
            ++$depth;
        } elseif ('}' === $character || ']' === $character) {
            if (0 === --$depth) {
                return substr($output, $start, $index - $start + 1);
            }
        }
    }

    return substr($output, $start);
}

function inferHandlerMessages(string $class, string $method): array
{
    if (!class_exists($class) || !method_exists($class, $method)) {
        return [];
    }
    try {
        $parameters = (new ReflectionMethod($class, $method))->getParameters();
        $type = ($parameters[0] ?? null)?->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        $messages = [];
        foreach ($types as $candidate) {
            if ($candidate instanceof ReflectionNamedType && !$candidate->isBuiltin()) {
                $messages[] = $candidate->getName();
            }
        }

        return $messages;
    } catch (Throwable) {
        return [];
    }
}

function definitionTagParameters(array $definition, string $tagName): array
{
    $parameters = [];
    foreach (is_array($definition['tags'] ?? null) ? $definition['tags'] : [] as $key => $tag) {
        if (is_array($tag) && $tagName === ($tag['name'] ?? null)) {
            $parameters[] = is_array($tag['parameters'] ?? null) ? $tag['parameters'] : [];
        } elseif ($tagName === $key) {
            foreach (is_array($tag) ? $tag : [] as $attributes) {
                $parameters[] = is_array($attributes) ? $attributes : [];
            }
        }
    }

    return $parameters;
}

function normalizeServices(array $container, array $types): array
{
    $services = [];
    $definitions = $container['definitions'] ?? $container['services'] ?? $container;
    foreach (is_array($definitions) ? $definitions : [] as $key => $definition) {
        if (!is_array($definition)) {
            continue;
        }

        $id = is_string($definition['id'] ?? null)
            ? $definition['id']
            : (is_string($definition['name'] ?? null)
                ? $definition['name']
                : (is_string($key) ? $key : null));
        if (null === $id || in_array($id, ['aliases', 'definitions', 'services'], true)) {
            continue;
        }

        $alias = is_string($definition['alias'] ?? null)
            ? $definition['alias']
            : null;
        $services[$id] = normalizeService($id, $definition, $alias);
    }

    foreach (is_array($container['services'] ?? null) ? $container['services'] : [] as $id => $className) {
        if (is_string($id) && is_string($className)) {
            $services[$id] = normalizeService($id, ['class' => $className], null);
        }
    }

    foreach (is_array($container['aliases'] ?? null) ? $container['aliases'] : [] as $key => $alias) {
        $metadata = is_array($alias) ? $alias : [];
        $id = is_string($metadata['id'] ?? null)
            ? $metadata['id']
            : (is_string($metadata['name'] ?? null)
                ? $metadata['name']
                : (is_string($key) ? $key : null));
        $target = is_string($alias)
            ? $alias
            : (is_string($metadata['service'] ?? null)
                ? $metadata['service']
                : (is_string($metadata['target'] ?? null) ? $metadata['target'] : null));
        if (null !== $id) {
            $services[$id] = normalizeService($id, $metadata, $target);
        }
    }

    $typesByService = normalizeAutowiringTypes($types);
    foreach ($services as $id => $service) {
        $service['autowiringTypes'] = $typesByService[$id] ?? [];
        $services[$id] = $service;
    }

    ksort($services);

    return array_values($services);
}

function normalizeService(string $id, array $metadata, ?string $alias): array
{
    $tags = [];
    foreach (is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [] as $key => $tag) {
        $name = is_string($key)
            ? $key
            : (is_string($tag) ? $tag : (is_array($tag) && is_string($tag['name'] ?? null) ? $tag['name'] : null));
        if (null !== $name) {
            $tags[] = $name;
        }
    }
    $tags = array_values(array_unique($tags));
    sort($tags);

    $decorates = is_string($metadata['decorates'] ?? null)
        ? $metadata['decorates']
        : (is_array($metadata['decoration'] ?? null) && is_string($metadata['decoration']['service'] ?? null)
            ? $metadata['decoration']['service']
            : null);
    $decorationStack = [];
    foreach (is_array($metadata['decoration_stack'] ?? null) ? $metadata['decoration_stack'] : [] as $decorator) {
        if (is_array($decorator) && is_string($decorator['id'] ?? null)) {
            $decorationStack[] = $decorator['id'];
        }
    }

    return [
        'id' => $id,
        'class' => is_string($metadata['class'] ?? null) ? $metadata['class'] : null,
        'alias' => $alias,
        'public' => is_bool($metadata['public'] ?? null) ? $metadata['public'] : null,
        'lazy' => is_bool($metadata['lazy'] ?? null)
            ? $metadata['lazy']
            : (is_string($metadata['lazy'] ?? null) && '' !== $metadata['lazy'] ? true : null),
        'deprecation' => normalizeDeprecation($metadata['deprecation_message'] ?? $metadata['deprecated'] ?? $metadata['deprecation'] ?? null),
        'tags' => $tags,
        'decorates' => $decorates,
        'decorationStack' => array_values(array_unique($decorationStack)),
        'autowiringTypes' => [],
    ];
}

function normalizeAutowiringTypes(array $output): array
{
    $typesByService = [];
    if (array_key_exists('definitions', $output) || array_key_exists('aliases', $output)) {
        foreach (is_array($output['definitions'] ?? null) ? $output['definitions'] : [] as $type => $_) {
            if (is_string($type)) {
                $typesByService[$type][] = $type;
            }
        }
        foreach (is_array($output['aliases'] ?? null) ? $output['aliases'] : [] as $type => $alias) {
            if (is_string($type) && is_array($alias) && is_string($alias['service'] ?? null)) {
                $typesByService[$alias['service']][] = $type;
            }
        }
        foreach (is_array($output['services'] ?? null) ? $output['services'] : [] as $type => $_) {
            if (is_string($type)) {
                $typesByService[$type][] = $type;
            }
        }
    } else {
        $types = is_array($output['types'] ?? null) ? $output['types'] : $output;
        foreach ($types as $key => $services) {
            $type = is_string($key)
                ? $key
                : (is_array($services) && is_string($services['type'] ?? null) ? $services['type'] : null);
            if (null === $type) {
                continue;
            }

            $serviceIds = is_array($services) && array_key_exists('services', $services)
                ? $services['services']
                : $services;
            foreach (serviceIds($serviceIds) as $serviceId) {
                $typesByService[$serviceId][] = $type;
            }
        }
    }

    foreach ($typesByService as $serviceId => $serviceTypes) {
        $serviceTypes = array_values(array_unique($serviceTypes));
        sort($serviceTypes);
        $typesByService[$serviceId] = $serviceTypes;
    }

    return $typesByService;
}

function serviceIds(mixed $services): array
{
    if (is_string($services)) {
        return [$services];
    }
    if (!is_array($services)) {
        return [];
    }
    if (is_string($services['id'] ?? null)) {
        return [$services['id']];
    }
    if (is_string($services['service'] ?? null)) {
        return [$services['service']];
    }

    $ids = [];
    foreach ($services as $service) {
        array_push($ids, ...serviceIds($service));
    }

    return array_values(array_unique($ids));
}

function normalizeParameters(array $output): array
{
    $parameters = $output['parameters'] ?? $output;
    $deprecations = [];
    if (is_array($parameters) && is_array($parameters['_deprecations'] ?? null)) {
        foreach ($parameters['_deprecations'] as $name => $deprecation) {
            if (is_string($name) && is_string($deprecation) && str_starts_with($deprecation, 'Since ')) {
                $deprecations[$name] = $deprecation;
            }
        }
    }

    $items = [];
    foreach (is_array($parameters) ? $parameters : [] as $name => $_) {
        if (!is_string($name) || '_deprecations' === $name) {
            continue;
        }

        $items[$name] = [
            'name' => $name,
            'deprecation' => $deprecations[$name] ?? null,
        ];
    }
    ksort($items);

    return array_values($items);
}

function normalizeDeprecation(mixed $deprecation): ?string
{
    if (is_string($deprecation)) {
        return '' !== $deprecation ? $deprecation : null;
    }
    if (true === $deprecation) {
        return 'Deprecated';
    }
    if (is_array($deprecation) && is_string($deprecation['message'] ?? null)) {
        return $deprecation['message'];
    }

    return null;
}

function splitDebugValues(mixed $value): array
{
    if (!is_string($value) || '' === $value || 'ANY' === $value) {
        return [];
    }

    return preg_split('/[|, ]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

function configNodeType(object $node): string
{
    $class = basename(str_replace('\\', '/', $node::class));

    return match ($class) {
        'ArrayNode', 'PrototypedArrayNode' => 'array',
        'BooleanNode' => 'boolean',
        'EnumNode' => 'enum',
        'FloatNode' => 'float',
        'IntegerNode' => 'integer',
        'ScalarNode' => 'scalar',
        'VariableNode' => 'variable',
        default => strtolower(preg_replace('/Node$/', '', $class)),
    };
}

function configDefaultSummary(object $node): ?string
{
    if (!method_exists($node, 'hasDefaultValue') || !$node->hasDefaultValue()) {
        return null;
    }
    $value = $node->getDefaultValue();

    return match (true) {
        null === $value => 'null',
        is_array($value) => sprintf('array (%d items)', count($value)),
        is_bool($value) => 'boolean',
        is_float($value) => 'float',
        is_int($value) => 'integer',
        is_string($value) => 'string',
        default => get_debug_type($value),
    };
}

function normalizeConfigExample(mixed $example): mixed
{
    if (null === $example || is_bool($example) || is_float($example) || is_int($example) || is_string($example)) {
        return $example;
    }
    if (is_array($example)) {
        $normalized = [];
        foreach (array_slice($example, 0, 20, true) as $key => $value) {
            $normalized[$key] = normalizeConfigExample($value);
        }

        return $normalized;
    }

    return null;
}

function normalizeConfigNode(object $node, int $depth = 0): array
{
    $normalized = [
        'name' => method_exists($node, 'getName') ? (string) $node->getName() : '',
        'type' => configNodeType($node),
        'required' => method_exists($node, 'isRequired') && $node->isRequired(),
        'hasDefault' => method_exists($node, 'hasDefaultValue') && $node->hasDefaultValue(),
        'defaultSummary' => configDefaultSummary($node),
        'info' => method_exists($node, 'getInfo') && is_string($node->getInfo()) ? $node->getInfo() : null,
        'example' => method_exists($node, 'getExample') ? normalizeConfigExample($node->getExample()) : null,
        'deprecated' => method_exists($node, 'isDeprecated') && $node->isDeprecated(),
        'allowedValues' => method_exists($node, 'getValues') ? normalizeConfigExample($node->getValues()) : null,
        'children' => [],
        'prototype' => null,
    ];
    if ($depth >= 32) {
        return $normalized;
    }
    if (method_exists($node, 'getChildren')) {
        foreach ($node->getChildren() as $child) {
            if (is_object($child)) {
                $normalized['children'][] = normalizeConfigNode($child, $depth + 1);
            }
        }
    }
    if (method_exists($node, 'getPrototype')) {
        $prototype = $node->getPrototype();
        if (is_object($prototype)) {
            $normalized['prototype'] = normalizeConfigNode($prototype, $depth + 1);
        }
    }

    return $normalized;
}
