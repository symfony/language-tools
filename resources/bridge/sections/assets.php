<?php

function bridgeAssetsSection(SymfonyLspBridgeContext $context): ?array
{
    $assets = [];
    $importMap = [];
    $warnings = [];
    $assetsComplete = false;
    $importMapComplete = false;

    if (interface_exists(Symfony\Component\AssetMapper\AssetMapperInterface::class)) {
        try {
            $application = new Symfony\Bundle\FrameworkBundle\Console\Application($context->kernel());
            $application->setAutoExit(false);
            $commandOptions = [
                '--format' => 'json',
                '--env' => $context->environment(),
                '--no-debug' => !$context->debug(),
                '--no-interaction' => true,
            ];
            $configuration = runJsonCommand($application, [
                'command' => 'debug:config',
                'name' => 'framework',
                'path' => 'asset_mapper',
                ...$commandOptions,
            ]);
            $configuration = is_array($configuration['asset_mapper'] ?? null) ? $configuration['asset_mapper'] : $configuration;
            $paths = is_array($configuration['paths'] ?? null) ? $configuration['paths'] : [];
            $projectRoot = Symfony\Component\Filesystem\Path::canonicalize(realpath($context->project()) ?: $context->project());
            $excludedPatterns = [];
            foreach (array_filter(is_array($configuration['excluded_patterns'] ?? null) ? $configuration['excluded_patterns'] : [], 'is_string') as $pattern) {
                $excludedPatterns[] = Symfony\Component\Finder\Glob::toRegex($pattern);
            }
            $excludeDotFiles = true === ($configuration['exclude_dotfiles'] ?? true);
            $importMapPath = is_string($configuration['importmap_path'] ?? null) ? $configuration['importmap_path'] : null;
            if ($application->find('debug:container')->getDefinition()->hasOption('show-arguments')) {
                $containerOptions = ['--show-hidden' => true, '--show-arguments' => true, ...$commandOptions];
                $repository = runJsonCommand($application, [
                    'command' => 'debug:container',
                    'name' => 'asset_mapper.repository',
                    ...$containerOptions,
                ]);
                if (is_array($repository['arguments'][0] ?? null)) {
                    $paths = $repository['arguments'][0];
                }
                $projectRoot = is_string($repository['arguments'][1] ?? null) ? $repository['arguments'][1] : $projectRoot;
                if (is_array($repository['arguments'][2] ?? null)) {
                    $excludedPatterns = array_values(array_filter($repository['arguments'][2], 'is_string'));
                }
                $excludeDotFiles = true === ($repository['arguments'][3] ?? $excludeDotFiles);
                $configReader = runJsonCommand($application, [
                    'command' => 'debug:container',
                    'name' => 'asset_mapper.importmap.config_reader',
                    ...$containerOptions,
                ]);
                $importMapPath = is_string($configReader['arguments'][0] ?? null) ? $configReader['arguments'][0] : $importMapPath;
            }
            $projectRoot = Symfony\Component\Filesystem\Path::canonicalize($projectRoot);
            foreach ($paths as $path => $namespace) {
                if (!is_string($path) || !is_string($namespace)) {
                    continue;
                }
                $absolutePath = bridgeAssetAbsolutePath($projectRoot, $path);
                if (null === $absolutePath) {
                    $warnings[] = sprintf('Asset path not found: %s', $path);
                    continue;
                }
                $finder = (new Symfony\Component\Finder\Finder())
                    ->files()
                    ->in($absolutePath)
                    ->ignoreDotFiles(false)
                    ->ignoreVCS(false)
                    ->notName('/\.php$/i');
                foreach ($finder as $file) {
                    $sourcePath = Symfony\Component\Filesystem\Path::canonicalize($file->getPathname());
                    if (bridgeAssetExcluded($sourcePath, $excludedPatterns, $excludeDotFiles)) {
                        continue;
                    }
                    $relativePath = Symfony\Component\Filesystem\Path::makeRelative($sourcePath, $absolutePath);
                    $logicalPath = ltrim(('' === $namespace ? '' : rtrim($namespace, '/').'/').$relativePath, '/');
                    $assets[$logicalPath] = [
                        'logicalPath' => $logicalPath,
                        'sourcePath' => $sourcePath,
                        'vendor' => !Symfony\Component\Filesystem\Path::isBasePath($projectRoot, $sourcePath) || str_contains('/'.$sourcePath, '/vendor/'),
                    ];
                }
            }
            ksort($assets);
            $assetsComplete = [] === $warnings;

            try {
                if (null !== $importMapPath && is_file($importMapPath)) {
                    ob_start();
                    try {
                        $entries = (static fn (string $path): mixed => require $path)($importMapPath);
                    } finally {
                        $output = ob_get_clean();
                    }
                    if (is_string($output) && '' !== trim($output)) {
                        $warnings[] = 'The importmap configuration produced output that was discarded.';
                    }
                    foreach (is_array($entries) ? $entries : [] as $name => $options) {
                        if (!is_string($name) || !is_array($options)) {
                            continue;
                        }
                        $importMap[$name] = [
                            'name' => $name,
                            'path' => is_string($options['path'] ?? null) ? $options['path'] : $name,
                            'entrypoint' => true === ($options['entrypoint'] ?? false),
                            'version' => is_string($options['version'] ?? null) ? $options['version'] : null,
                        ];
                    }
                    ksort($importMap);
                    $importMapComplete = is_array($entries);
                }
            } catch (Throwable $error) {
                $warnings[] = 'Import map: '.$error->getMessage();
            }
        } catch (Throwable $error) {
            $warnings[] = 'AssetMapper: '.$error->getMessage();
        }
    }

    sort($warnings);
    $section = [
        'assetsComplete' => $assetsComplete,
        'importMapComplete' => $importMapComplete,
        'assets' => array_values($assets),
        'importMap' => array_values($importMap),
        'resources' => [],
        'warnings' => $warnings,
    ];
    $section['generation'] = hash('sha256', json_encode($section, JSON_THROW_ON_ERROR));

    return $section;
}

function bridgeAssetAbsolutePath(string $projectRoot, string $path): ?string
{
    $absolute = Symfony\Component\Filesystem\Path::isAbsolute($path)
        ? Symfony\Component\Filesystem\Path::canonicalize($path)
        : Symfony\Component\Filesystem\Path::join($projectRoot, $path);
    $realPath = realpath($absolute);

    return false !== $realPath && is_dir($realPath) ? $realPath : null;
}

function bridgeAssetExcluded(string $path, array $patterns, bool $excludeDotFiles): bool
{
    foreach ($patterns as $pattern) {
        if (1 === preg_match($pattern, $path)) {
            return true;
        }
    }

    return $excludeDotFiles && str_starts_with(basename($path), '.');
}
