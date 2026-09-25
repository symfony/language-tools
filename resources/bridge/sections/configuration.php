<?php

function symfonyLspBridgeConfigurationSection(SymfonyLspBridgeContext $context): ?array
{
    $project = $context->project();
    $bundles = [];
    $warnings = [];
    $complete = true;
    try {
        $builder = $context->containerBuilder();
        foreach ($context->extensions() as $alias => $extension) {
            try {
                if (!method_exists($extension, 'getConfiguration')) {
                    continue;
                }
                $configuration = $extension->getConfiguration([], $builder);
                if (null === $configuration) {
                    continue;
                }
                $bundles[] = [
                    'alias' => $alias,
                    'class' => $extension::class,
                    'tree' => symfonyLspBridgeNormalizeConfigNode($configuration->getConfigTreeBuilder()->buildTree()),
                ];
            } catch (Throwable) {
                $warnings[] = sprintf('The %s configuration tree is unavailable.', $extension::class);
            }
        }
    } catch (Throwable $error) {
        $complete = false;
        $context->addError('configuration', $error);
    }
    usort($bundles, static fn (array $left, array $right): int => $left['alias'] <=> $right['alias']);
    sort($warnings);
    $resources = [];
    $configDir = Symfony\Component\Filesystem\Path::join($project, 'config');
    if (is_dir($configDir)) {
        $finder = (new Symfony\Component\Finder\Finder())
            ->files()
            ->in($configDir)
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->name('/\.(?:php|xml|yaml|yml)$/i');
        foreach ($finder as $file) {
            $resources[] = $file->getPathname();
        }
    }
    sort($resources);
    $section = [
        'complete' => $complete,
        'bundles' => $bundles,
        'resources' => $resources,
        'warnings' => $warnings,
    ];

    return $section;
}
