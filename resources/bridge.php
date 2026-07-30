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

    return [
        'id' => $id,
        'class' => is_string($metadata['class'] ?? null) ? $metadata['class'] : null,
        'alias' => $alias,
        'public' => is_bool($metadata['public'] ?? null) ? $metadata['public'] : null,
        'lazy' => is_bool($metadata['lazy'] ?? null) ? $metadata['lazy'] : null,
        'deprecation' => normalizeDeprecation($metadata['deprecated'] ?? $metadata['deprecation'] ?? null),
        'tags' => $tags,
        'decorates' => $decorates,
        'autowiringTypes' => [],
    ];
}

function normalizeAutowiringTypes(array $output): array
{
    $typesByService = [];
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
    $items = [];
    foreach (is_array($parameters) ? $parameters : [] as $key => $parameter) {
        $name = is_array($parameter) && is_string($parameter['name'] ?? null)
            ? $parameter['name']
            : (is_string($key) ? $key : null);
        if (null === $name) {
            continue;
        }

        $items[$name] = [
            'name' => $name,
            'deprecation' => is_array($parameter)
                ? normalizeDeprecation($parameter['deprecated'] ?? $parameter['deprecation'] ?? null)
                : null,
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
