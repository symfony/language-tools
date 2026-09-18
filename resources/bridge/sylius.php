<?php

/*
 * Sylius is the one vendor this bridge knows by name. Its theme bundle keeps
 * template directories out of every Twig loader, so they are derived from the
 * theme source configuration instead. Keep vendor knowledge in this file, and
 * prefer a framework convention over adding more of it.
 */

/*
 * Sylius themes hold template directories no loader exposes: the theme loader
 * resolves them per request from the active theme. Their layout is fixed, so
 * the directories map onto loader paths, which resolves every theme template
 * without knowing which channel selects which theme.
 */
function symfonyLspBridgeSyliusThemePaths(SymfonyLspBridgeContext $context): array
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
        foreach (symfonyLspBridgeSyliusThemeDirectories($directory, $filename, $depth) as $theme) {
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
function symfonyLspBridgeSyliusThemeDirectories(string $directory, string $filename, int $depth): array
{
    if (!is_dir($directory)) {
        return [];
    }
    $themes = is_file($directory.'/'.$filename) ? [$directory] : [];
    if ($depth > 0) {
        foreach (symfonyLspBridgeDirectoryEntries($directory) as $entry) {
            $themes = array_merge($themes, symfonyLspBridgeSyliusThemeDirectories($directory.'/'.$entry, $filename, $depth - 1));
        }
    }

    return $themes;
}
