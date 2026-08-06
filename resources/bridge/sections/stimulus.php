<?php

function bridgeStimulusSection(SymfonyLspBridgeContext $context): ?array
{
    $controllers = [];
    $resources = [];
    $warnings = [];
    $complete = false;

    if (class_exists(Symfony\UX\StimulusBundle\StimulusBundle::class)) {
        try {
            $kernel = $context->kernel();
            $enabled = false;
            foreach ($kernel->getBundles() as $bundle) {
                if ($bundle instanceof Symfony\UX\StimulusBundle\StimulusBundle) {
                    $enabled = true;
                    break;
                }
            }
            if ($enabled) {
                $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
                $application->setAutoExit(false);
                $configuration = runJsonCommand($application, [
                    'command' => 'debug:config',
                    'name' => 'stimulus',
                    '--format' => 'json',
                    '--env' => $context->environment(),
                    '--no-debug' => !$context->debug(),
                    '--no-interaction' => true,
                ]);
                $configuration = is_array($configuration['stimulus'] ?? null) ? $configuration['stimulus'] : $configuration;
                $controllerPaths = array_values(array_filter(is_array($configuration['controller_paths'] ?? null) ? $configuration['controller_paths'] : [], 'is_string'));
                $controllersJson = is_string($configuration['controllers_json'] ?? null) ? $configuration['controllers_json'] : null;
                if (null !== $controllersJson && is_file($controllersJson)) {
                    $resources[] = realpath($controllersJson) ?: $controllersJson;
                    foreach (bridgeStimulusUxControllers($context->project(), $controllersJson, $warnings) as $name => $controller) {
                        $controllers[$name] = $controller;
                    }
                }
                foreach ($controllerPaths as $controllerPath) {
                    foreach (bridgeStimulusLocalControllers($context->project(), $controllerPath) as $name => $controller) {
                        $controllers[$name] = $controller;
                    }
                }
                $complete = [] === $warnings;
            }
        } catch (Throwable $error) {
            $warnings[] = 'Stimulus: '.$error->getMessage();
        }
    }

    ksort($controllers);
    sort($resources);
    sort($warnings);
    $section = [
        'complete' => $complete,
        'controllers' => array_values($controllers),
        'resources' => $resources,
        'warnings' => $warnings,
    ];
    $section['generation'] = hash('sha256', json_encode($section, JSON_THROW_ON_ERROR));

    return $section;
}

function bridgeStimulusLocalControllers(string $projectRoot, string $controllerPath): array
{
    $controllers = [];
    $realPath = realpath($controllerPath);
    if (false === $realPath || !is_dir($realPath)) {
        return [];
    }
    $finder = (new Symfony\Component\Finder\Finder())
        ->files()
        ->in($realPath)
        ->ignoreDotFiles(false)
        ->ignoreVCS(false)
        ->name('/[-_]controller\.[jt]s$/');
    foreach ($finder as $file) {
        $relative = Symfony\Component\Filesystem\Path::makeRelative($file->getPathname(), $realPath);
        if (!preg_match('/^.*[-_](controller\.[jt]s)$/', $relative, $match)) {
            continue;
        }
        if ('ts' === Symfony\Component\Filesystem\Path::getExtension($relative, true) && is_file(Symfony\Component\Filesystem\Path::changeExtension($file->getPathname(), 'js'))) {
            continue;
        }
        $name = str_replace(['_'.$match[1], '-'.$match[1]], '', $relative);
        $name = str_replace(['_', '/', '\\'], ['-', '--', '--'], $name);
        $sourcePath = $file->getRealPath();
        if (false === $sourcePath) {
            continue;
        }
        $controllers[$name] = bridgeStimulusController($projectRoot, $name, $sourcePath, null);
    }

    return $controllers;
}

function bridgeStimulusUxControllers(string $projectRoot, string $controllersJson, array &$warnings): array
{
    try {
        $configuration = json_decode((string) file_get_contents($controllersJson), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        $warnings[] = 'Stimulus controllers.json: '.$error->getMessage();

        return [];
    }
    $controllers = [];
    foreach (is_array($configuration['controllers'] ?? null) ? $configuration['controllers'] : [] as $packageName => $packageControllers) {
        if (!is_string($packageName) || !is_array($packageControllers)) {
            continue;
        }
        $composerPackage = ltrim($packageName, '@');
        $packagePath = Composer\InstalledVersions::isInstalled($composerPackage) ? Composer\InstalledVersions::getInstallPath($composerPackage) : null;
        if (!is_string($packagePath)) {
            $warnings[] = sprintf('Stimulus package not found: %s', $composerPackage);
            continue;
        }
        $packageJson = Symfony\Component\Filesystem\Path::join($packagePath, 'assets/package.json');
        if (!is_file($packageJson)) {
            $packageJson = Symfony\Component\Filesystem\Path::join($packagePath, 'Resources/assets/package.json');
        }
        if (!is_file($packageJson)) {
            $warnings[] = sprintf('Stimulus package metadata not found: %s', $composerPackage);
            continue;
        }
        try {
            $packageMetadata = json_decode((string) file_get_contents($packageJson), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            $warnings[] = sprintf('Stimulus package metadata for %s: %s', $composerPackage, $error->getMessage());
            continue;
        }
        $packageDirectory = dirname($packageJson);
        $availableControllers = is_array($packageMetadata['symfony']['controllers'] ?? null) ? $packageMetadata['symfony']['controllers'] : [];
        foreach ($packageControllers as $controllerName => $localConfiguration) {
            if (!is_string($controllerName) || !is_array($localConfiguration) || true !== ($localConfiguration['enabled'] ?? false)) {
                continue;
            }
            $packageConfiguration = $availableControllers[$controllerName] ?? null;
            if (!is_array($packageConfiguration) || !is_string($packageConfiguration['main'] ?? null)) {
                $warnings[] = sprintf('Stimulus controller not found: %s/%s', $packageName, $controllerName);
                continue;
            }
            $name = substr($packageName.'/'.$controllerName, 1);
            $name = str_replace(['_', '/'], ['-', '--'], $name);
            if (is_string($packageConfiguration['name'] ?? null)) {
                $name = str_replace('/', '--', $packageConfiguration['name']);
            }
            if (is_string($localConfiguration['name'] ?? null)) {
                $name = str_replace('/', '--', $localConfiguration['name']);
            }
            $sourcePath = realpath(Symfony\Component\Filesystem\Path::join($packageDirectory, $packageConfiguration['main']));
            if (false === $sourcePath || !is_file($sourcePath)) {
                $warnings[] = sprintf('Stimulus controller source not found: %s/%s', $packageName, $controllerName);
                continue;
            }
            $lazy = 'lazy' === ($localConfiguration['fetch'] ?? 'eager');
            $controllers[$name] = bridgeStimulusController($projectRoot, $name, $sourcePath, $lazy);
        }
    }

    return $controllers;
}

function bridgeStimulusController(string $projectRoot, string $name, string $sourcePath, ?bool $lazy): array
{
    $contents = file_get_contents($sourcePath);
    $contents = false === $contents ? '' : $contents;
    $metadata = bridgeStimulusJavascriptMetadata($contents);
    $root = Symfony\Component\Filesystem\Path::canonicalize(realpath($projectRoot) ?: $projectRoot);
    $sourcePath = Symfony\Component\Filesystem\Path::canonicalize($sourcePath);

    return [
        'name' => $name,
        'sourcePath' => $sourcePath,
        'lazy' => $lazy ?? 1 === preg_match('/\/\*!?\s*stimulusFetch:\s*\'lazy\'\s*\*\//i', $contents),
        'vendor' => !Symfony\Component\Filesystem\Path::isBasePath($root, $sourcePath) || str_contains('/'.$sourcePath, '/vendor/'),
        ...$metadata,
    ];
}

function bridgeStimulusJavascriptMetadata(string $contents): array
{
    preg_match_all('/^[ \t]*(?:async\s+)?([A-Za-z_$][A-Za-z0-9_$]*)\s*\([^)]*\)\s*(?::\s*[^\{\r\n]+)?\s*\{/m', $contents, $methodMatches);
    $actions = array_values(array_diff(array_unique($methodMatches[1]), ['connect', 'constructor', 'disconnect', 'initialize']));
    sort($actions);
    $metadata = ['actions' => $actions];
    foreach (['targets', 'outlets', 'classes'] as $property) {
        $values = [];
        if (preg_match('/\bstatic\s+'.preg_quote($property, '/').'\s*=\s*\[(.*?)\]/s', $contents, $match)) {
            preg_match_all('/([\'"])([^\'"]+)\1/', $match[1], $valueMatches);
            $values = array_values(array_unique($valueMatches[2]));
            sort($values);
        }
        $metadata[$property] = $values;
    }
    $values = [];
    if (preg_match('/\bstatic\s+values\s*=\s*\{(.*?)\}/s', $contents, $match)) {
        preg_match_all('/(?:^|,)\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*:/m', $match[1], $valueMatches);
        $values = array_values(array_unique($valueMatches[1]));
        sort($values);
    }
    $metadata['values'] = $values;

    return $metadata;
}
