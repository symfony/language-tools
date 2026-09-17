<?php

function symfonyLspBridgeTwigSection(SymfonyLspBridgeContext $context): ?array
{
    $paths = [];
    $globals = [];
    $warnings = [];
    $complete = true;
    if (class_exists(Twig\Environment::class)) {
        try {
            $application = $context->application();
            if (!$application->has('debug:twig')) {
                $complete = false;
                $warnings[] = 'The debug:twig command is unavailable.';
            } else {
                $twig = symfonyLspBridgeRunJsonCommand($application, [
                    'command' => 'debug:twig',
                    '--format' => 'json',
                    ...$context->commandOptions(),
                ]);
                foreach (is_array($twig['globals'] ?? null) ? array_keys($twig['globals']) : [] as $name) {
                    if (is_string($name)) {
                        $globals[] = $name;
                    }
                }
                foreach (is_array($twig['loader_paths'] ?? null) ? $twig['loader_paths'] : [] as $namespace => $loaderPaths) {
                    foreach (is_array($loaderPaths) ? $loaderPaths : [] as $path) {
                        if (is_string($namespace) && is_string($path)) {
                            $paths[] = ['namespace' => $namespace, 'path' => $path];
                        }
                    }
                }
            }
            if ([] === $paths) {
                // theme loaders, such as the Sylius theme bundle, decorate the
                // filesystem loader and hide every path from debug:twig
                $paths = symfonyLspBridgeTwigLoaderPaths($application);
            }
            if ([] === $paths) {
                $paths = symfonyLspBridgeTwigConventionPaths($context);
            }
            $paths = array_merge($paths, symfonyLspBridgeTwigThemePaths($context));
        } catch (Throwable $error) {
            $context->addError('twig', $error);
        }
    }
    $paths = array_values(array_unique($paths, SORT_REGULAR));
    usort($paths, static fn (array $a, array $b): int => [$a['namespace'], $a['path']] <=> [$b['namespace'], $b['path']]);
    sort($globals);
    $section = [
        'complete' => $complete,
        'generation' => hash('sha256', json_encode([$paths, $globals], JSON_THROW_ON_ERROR)),
        'paths' => $paths,
        'globals' => $globals,
        'resources' => [],
        'warnings' => $warnings,
    ];

    return $section;
}

/*
 * Sylius themes hold template directories no loader exposes: the theme loader
 * resolves them per request from the active theme. Their layout is fixed, so
 * the directories map onto loader paths, which resolves every theme template
 * without knowing which channel selects which theme.
 */
function symfonyLspBridgeTwigThemePaths(SymfonyLspBridgeContext $context): array
{
    try {
        if (!$context->hasExtension('sylius_theme')) {
            return [];
        }
        $configuration = $context->configuration('sylius_theme');
    } catch (Throwable) {
        return [];
    }
    $filesystem = is_array($configuration) ? $configuration['sources']['filesystem'] ?? null : null;
    if (!is_array($filesystem) || false === ($filesystem['enabled'] ?? true)) {
        return [];
    }
    $filename = is_string($filesystem['filename'] ?? null) && '' !== $filesystem['filename'] ? $filesystem['filename'] : 'composer.json';
    $depth = is_numeric($filesystem['scan_depth'] ?? null) ? max(0, (int) $filesystem['scan_depth']) : 1;
    $project = rtrim($context->project(), '/\\');
    $paths = [];
    foreach (is_array($filesystem['directories'] ?? null) ? $filesystem['directories'] : [] as $directory) {
        if (!is_string($directory) || '' === $directory) {
            continue;
        }
        if (!preg_match('{^(?:/|[A-Za-z]:[\\\\/])}', $directory)) {
            $directory = $project.'/'.$directory;
        }
        foreach (symfonyLspBridgeThemeDirectories($directory, $filename, $depth) as $theme) {
            $templates = $theme.'/templates';
            if (!is_dir($templates)) {
                continue;
            }
            $paths[] = ['namespace' => '(None)', 'path' => $templates];
            foreach (symfonyLspBridgeDirectoryEntries($templates.'/bundles') as $entry) {
                // a theme names the directory after the bundle, the namespace drops the suffix
                $namespace = str_ends_with($entry, 'Bundle') ? substr($entry, 0, -6) : $entry;
                $paths[] = ['namespace' => '@'.$namespace, 'path' => $templates.'/bundles/'.$entry];
            }
        }
    }

    return $paths;
}

/*
 * Locates theme roots the way the filesystem theme source does: every
 * directory holding the configuration file, within the configured depth.
 */
function symfonyLspBridgeThemeDirectories(string $directory, string $filename, int $depth): array
{
    if (!is_dir($directory)) {
        return [];
    }
    $themes = is_file($directory.'/'.$filename) ? [$directory] : [];
    if ($depth > 0) {
        foreach (symfonyLspBridgeDirectoryEntries($directory) as $entry) {
            $themes = array_merge($themes, symfonyLspBridgeThemeDirectories($directory.'/'.$entry, $filename, $depth - 1));
        }
    }

    return $themes;
}

/** @return list<string> the subdirectory names of a directory, sorted */
function symfonyLspBridgeDirectoryEntries(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }
    $entries = [];
    foreach (scandir($directory) ?: [] as $entry) {
        if ('.' !== $entry && '..' !== $entry && is_dir($directory.'/'.$entry)) {
            $entries[] = $entry;
        }
    }
    sort($entries);

    return $entries;
}

/*
 * Reads the paths from the filesystem loaders the Twig environment actually
 * uses, which a decorating loader keeps reachable but hides from debug:twig.
 * The environment is private, so it is read from the debug:twig command that
 * already received it.
 */
function symfonyLspBridgeTwigLoaderPaths(object $application): array
{
    $paths = [];
    try {
        if (!$application->has('debug:twig')) {
            return [];
        }
        $command = $application->find('debug:twig');
        if ($command instanceof Symfony\Component\Console\Command\LazyCommand) {
            $command = $command->getCommand();
        }
        $environment = null;
        foreach ((new ReflectionObject($command))->getProperties() as $property) {
            $value = $property->isInitialized($command) ? $property->getValue($command) : null;
            if ($value instanceof Twig\Environment) {
                $environment = $value;
                break;
            }
        }
        if (null === $environment) {
            return [];
        }
        foreach (symfonyLspBridgeTwigFilesystemLoaders($environment->getLoader()) as $loader) {
            foreach ($loader->getNamespaces() as $namespace) {
                foreach ($loader->getPaths($namespace) as $path) {
                    if (is_string($path) && '' !== $path) {
                        $paths[] = [
                            'namespace' => Twig\Loader\FilesystemLoader::MAIN_NAMESPACE === $namespace ? '(None)' : '@'.$namespace,
                            'path' => $path,
                        ];
                    }
                }
            }
        }
    } catch (Throwable) {
        return [];
    }

    return $paths;
}

/*
 * Collects every filesystem loader reachable from a loader, following chain
 * loaders and the inner loader a decorating loader holds in a property.
 */
function symfonyLspBridgeTwigFilesystemLoaders(object $loader, array &$visited = [], int $depth = 0): array
{
    if ($depth > 5 || in_array($loader, $visited, true)) {
        return [];
    }
    $visited[] = $loader;
    if ($loader instanceof Twig\Loader\FilesystemLoader) {
        return [$loader];
    }
    $loaders = [];
    if ($loader instanceof Twig\Loader\ChainLoader) {
        foreach ($loader->getLoaders() as $inner) {
            $loaders = array_merge($loaders, symfonyLspBridgeTwigFilesystemLoaders($inner, $visited, $depth + 1));
        }

        return $loaders;
    }
    foreach ((new ReflectionObject($loader))->getProperties() as $property) {
        $value = $property->isInitialized($loader) ? $property->getValue($loader) : null;
        foreach (is_array($value) ? $value : [$value] as $candidate) {
            if ($candidate instanceof Twig\Loader\LoaderInterface) {
                $loaders = array_merge($loaders, symfonyLspBridgeTwigFilesystemLoaders($candidate, $visited, $depth + 1));
            }
        }
    }

    return $loaders;
}

/*
 * Rebuilds the loader paths from the sources TwigBundle itself registers:
 * the configured paths and default path plus the bundle template directories
 * and their application-level overrides.
 */
function symfonyLspBridgeTwigConventionPaths(SymfonyLspBridgeContext $context): array
{
    $paths = [];
    $project = rtrim($context->project(), '/\\');
    $defaultPath = $project.'/templates';
    try {
        $configuration = $context->configuration('twig');
        if (is_string($configuration['default_path'] ?? null) && '' !== $configuration['default_path']) {
            $defaultPath = $configuration['default_path'];
        }
        foreach (is_array($configuration['paths'] ?? null) ? $configuration['paths'] : [] as $path => $namespace) {
            if (!is_string($path) || '' === $path) {
                continue;
            }
            $paths[] = [
                'namespace' => is_string($namespace) && '' !== $namespace ? '@'.ltrim($namespace, '@') : '(None)',
                'path' => $path,
            ];
        }
    } catch (Throwable) {
    }
    try {
        $kernel = $context->kernel();
        if (method_exists($kernel, 'getBundles')) {
            foreach ($kernel->getBundles() as $bundle) {
                if (!is_object($bundle) || !method_exists($bundle, 'getName') || !method_exists($bundle, 'getPath')) {
                    continue;
                }
                $bundleName = (string) $bundle->getName();
                $namespace = '@'.(str_ends_with($bundleName, 'Bundle') ? substr($bundleName, 0, -6) : $bundleName);
                $bundlePath = rtrim((string) $bundle->getPath(), '/\\');
                $directories = [];
                if (is_dir($directory = $project.'/templates/bundles/'.$bundleName)) {
                    $directories[] = $directory;
                }
                if (is_dir($directory = $bundlePath.'/Resources/views') || is_dir($directory = $bundlePath.'/templates')) {
                    $directories[] = $directory;
                }
                foreach ($directories as $directory) {
                    $paths[] = ['namespace' => $namespace, 'path' => $directory];
                }
                if ([] !== $directories) {
                    // TwigBundle registers the bundle views directory under a
                    // second namespace, so an override can extend the template
                    // it overrides
                    $paths[] = ['namespace' => '@!'.substr($namespace, 1), 'path' => end($directories)];
                }
            }
        }
    } catch (Throwable) {
    }
    if (is_dir($defaultPath)) {
        $paths[] = ['namespace' => '(None)', 'path' => $defaultPath];
    }

    return $paths;
}
