<?php

function bridgeConfigurationSection(SymfonyLspBridgeContext $context): ?array
{
    $project = $context->project();
    $bundles = [];
    $warnings = [];
    $complete = true;
    try {
        $kernel = $context->kernel();
        $builder = new Symfony\Component\DependencyInjection\ContainerBuilder();
        $builder->setParameter('kernel.environment', $context->environment());
        $builder->setParameter('kernel.debug', $context->debug());
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
        $context->addError('configuration', $error->getMessage());
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
    $section = [
        'complete' => $complete,
        'generation' => hash('sha256', json_encode($bundles, JSON_THROW_ON_ERROR)),
        'bundles' => $bundles,
        'resources' => $resources,
        'warnings' => $warnings,
    ];

    return $section;
}
