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

            $kernel = new $kernelClass(
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
        } finally {
            if (is_object($kernel) && method_exists($kernel, 'shutdown')) {
                $kernel->shutdown();
            }
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

            $kernel = new $kernelClass(
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
        } finally {
            if (is_object($kernel) && method_exists($kernel, 'shutdown')) {
                $kernel->shutdown();
            }
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
            $kernel = new $kernelClass(
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
        } finally {
            if (is_object($kernel) && method_exists($kernel, 'shutdown')) {
                $kernel->shutdown();
            }
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
            $kernel = new $kernelClass(is_string($environment) ? $environment : 'dev', !in_array($debug, ['0', 'false'], true));
            $kernel->boot();
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
        } finally {
            if (is_object($kernel) && method_exists($kernel, 'shutdown')) {
                $kernel->shutdown();
            }
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

function runJsonCommand(object $application, array $arguments): array
{
    $output = new Symfony\Component\Console\Output\BufferedOutput();
    $exitCode = $application->run(new Symfony\Component\Console\Input\ArrayInput($arguments), $output);
    if (0 !== $exitCode) {
        throw new RuntimeException(sprintf('%s exited with status %d.', $arguments['command'], $exitCode));
    }

    $result = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result)) {
        throw new RuntimeException(sprintf('%s did not return a JSON object or array.', $arguments['command']));
    }

    return $result;
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
