<?php

function bridgeTwigSection(SymfonyLspBridgeContext $context): ?array
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
                $twig = runJsonCommand($application, [
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
                $paths = bridgeTwigConventionPaths($context, $application);
            }
        } catch (Throwable) {
            $context->addError('twig');
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
 * Rebuilds the loader paths from the sources TwigBundle itself registers:
 * the configured paths and default path plus the bundle template directories
 * and their application-level overrides.
 */
function bridgeTwigConventionPaths(SymfonyLspBridgeContext $context, object $application): array
{
    $paths = [];
    $project = rtrim($context->project(), '/\\');
    $defaultPath = $project.'/templates';
    try {
        $configuration = runJsonCommand($application, [
            'command' => 'debug:config',
            'name' => 'twig',
            '--format' => 'json',
            ...$context->commandOptions(),
        ]);
        $configuration = is_array($configuration['twig'] ?? null) ? $configuration['twig'] : $configuration;
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
                $candidates = [
                    $project.'/templates/bundles/'.$bundleName,
                    rtrim((string) $bundle->getPath(), '/\\').'/Resources/views',
                    rtrim((string) $bundle->getPath(), '/\\').'/templates',
                ];
                foreach ($candidates as $directory) {
                    if (is_dir($directory)) {
                        $paths[] = ['namespace' => $namespace, 'path' => $directory];
                    }
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
