<?php

namespace Symfony\Lsp\Server;

use Symfony\Lsp\Feature\DiagnosticProviderRegistry;
use Symfony\Lsp\Index\ApplicationSourceScanner;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Project\WorkspaceConfiguration;
use Symfony\Lsp\Runtime\ProjectRuntimeRefresher;

use function Amp\async;

/**
 * Applies what changed in the workspace in one order: projects, settings, file
 * watchers, source index, workspace trust and then diagnostics.
 */
final class WorkspaceSynchronizer
{
    public function __construct(
        private readonly WorkspaceConfiguration $workspaceConfiguration,
        private readonly WorkspaceFileWatcher $fileWatcher,
        private readonly ApplicationSourceScanner $sourceScanner,
        private readonly ProjectRuntimeRefresher $runtimeRefresher,
        private readonly DiagnosticProviderRegistry $diagnosticProviders,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly ServerLogger $logger,
    ) {
    }

    /**
     * @param array<array-key, mixed>|null        $workspaceFolders the change event of the workspace folders
     * @param list<array{uri: string, type: int}> $watchedFiles
     */
    public function synchronize(bool $initialization = false, bool $settings = false, ?array $workspaceFolders = null, array $watchedFiles = []): void
    {
        $files = [];
        $rediscoverProjects = false;
        $reloadConfiguration = false;
        $refreshFileWatchers = false;
        $rescanSources = false;
        foreach ($watchedFiles as $file) {
            $basename = basename($this->uriToPathConverter->convert($file['uri']) ?? '');
            $composer = \in_array($basename, ['composer.json', 'composer.lock'], true);
            $files[] = ['uri' => $file['uri'], 'deleted' => 3 === $file['type'], 'composer' => $composer];
            $rediscoverProjects = $rediscoverProjects || $composer;
            $reloadConfiguration = $reloadConfiguration || ProjectConfiguration::FILE_NAME === $basename;
            $rescanSources = $rescanSources || '.gitignore' === $basename;
            $refreshFileWatchers = $refreshFileWatchers || $this->fileWatcher->requiresRefreshForChange($file['uri'], $file['type']);
        }

        // rediscovery rebuilds the source index of every project, so it applies these changes itself
        if (!$rediscoverProjects) {
            foreach ($files as $file) {
                $this->runtimeRefresher->refreshUri($file['uri'], $this->sourceScanner->refreshUri($file['uri'], $file['deleted']));
            }
        }

        $projectsChanged = null !== $workspaceFolders || $rediscoverProjects || $reloadConfiguration;
        $refreshSettings = $initialization || $settings || $projectsChanged;
        if (!$refreshSettings && !$refreshFileWatchers && !$rescanSources) {
            return;
        }

        async(function () use ($initialization, $workspaceFolders, $files, $rediscoverProjects, $reloadConfiguration, $refreshFileWatchers, $projectsChanged, $refreshSettings): void {
            $configurationReady = true;
            try {
                if (null !== $workspaceFolders) {
                    $this->workspaceConfiguration->changeWorkspaceFolders($workspaceFolders);
                } elseif ($reloadConfiguration) {
                    $this->workspaceConfiguration->reloadProjectConfiguration();
                } elseif ($rediscoverProjects) {
                    $this->workspaceConfiguration->rediscoverProjects();
                }
                if ($refreshSettings) {
                    $this->workspaceConfiguration->refreshProjectSettings();
                }
                if ($initialization) {
                    $this->fileWatcher->register();
                } elseif ($projectsChanged || $refreshFileWatchers) {
                    $this->fileWatcher->refresh();
                }
            } catch (InvalidConfigurationException $error) {
                $configurationReady = false;
                $this->logger->error($error);
            }

            $rediscoveredFiles = [];
            if ($rediscoverProjects) {
                foreach ($files as $file) {
                    $rediscoveredFiles[] = [
                        ...$file,
                        'source' => $this->sourceScanner->refreshUri($file['uri'], $file['deleted']),
                    ];
                }
            }
            $this->sourceScanner->indexAll();
            $initializedProjects = $configurationReady && $refreshSettings
                ? $this->workspaceConfiguration->requestWorkspaceTrust()
                : [];
            foreach ($rediscoveredFiles as $file) {
                if ($file['composer']) {
                    $this->runtimeRefresher->refreshAfterRediscovery($file['uri'], $initializedProjects);
                } else {
                    $this->runtimeRefresher->refreshUri($file['uri'], $file['source']);
                }
            }
            $this->diagnosticProviders->refreshAll();
        })->ignore();
    }
}
