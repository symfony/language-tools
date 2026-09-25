<?php

function symfonyLspBridgeConfigurationSection(SymfonyLspBridgeContext $context): array
{
    $bundles = [];
    $warnings = [];
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
        $context->addError('configuration', $error);
    }
    usort($bundles, static fn (array $left, array $right): int => $left['alias'] <=> $right['alias']);
    sort($warnings);

    return [
        'bundles' => $bundles,
        'warnings' => $warnings,
    ];
}
