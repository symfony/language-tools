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
                $items[] = [
                    'name' => $name,
                    'path' => is_string($route['path'] ?? null) ? $route['path'] : null,
                    'methods' => $methods,
                    'schemes' => $schemes,
                    'host' => $host,
                    'controller' => is_string($route['defaults']['_controller'] ?? null)
                        ? $route['defaults']['_controller']
                        : null,
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

function splitDebugValues(mixed $value): array
{
    if (!is_string($value) || '' === $value || 'ANY' === $value) {
        return [];
    }

    return preg_split('/[|, ]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
