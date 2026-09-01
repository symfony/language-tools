<?php

namespace Symfony\Lsp\Tests\Support\Bridge;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Lsp\Feature\Configuration\ConfigurationValidationRegistry;
use Symfony\Lsp\Feature\Configuration\ProjectConfigurationValidationSnapshotLoader;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Runtime\BridgeInstaller;
use Symfony\Lsp\Runtime\ContainerPathMapper;
use Symfony\Lsp\Runtime\ProcessRunnerInterface;
use Symfony\Lsp\Runtime\ProjectRuntimeInitializer;
use Symfony\Lsp\Runtime\RuntimeConfiguration;
use Symfony\Lsp\Runtime\RuntimeSnapshotLoaderRegistry;
use Symfony\Lsp\Runtime\RuntimeSnapshotState;
use Symfony\Lsp\Runtime\RuntimeSnapshotStore;
use Symfony\Lsp\Server\SensitiveDataRedactor;
use Symfony\Lsp\Server\ServerLogger;

final class ProjectRuntimeInitializerFixtureBuilder
{
    private readonly BridgeInstaller $bridgeInstaller;

    public function __construct(string $source, ?BridgeInstaller $bridgeInstaller = null)
    {
        $this->bridgeInstaller = $bridgeInstaller ?? new BridgeInstaller($source, 'test', new Filesystem());
    }

    public function build(
        ProcessRunnerInterface $processRunner,
        RuntimeSnapshotLoaderRegistry $snapshotLoaders,
        ProjectRegistry $projects,
        ?RuntimeConfiguration $configuration = null,
        ?ProjectConfigurationValidationSnapshotLoader $configurationValidationLoader = null,
        ?RuntimeSnapshotStore $snapshotStore = null,
        ?RuntimeSnapshotState $snapshotState = null,
        ?ProjectIndexStatusRegistry $statuses = null,
        ?ServerLogger $logger = null,
        string $releaseMetadataUrl = '',
    ): ProjectRuntimeInitializer {
        $configuration ??= new RuntimeConfiguration();

        return new ProjectRuntimeInitializer(
            $this->bridgeInstaller,
            $processRunner,
            $snapshotLoaders,
            $configuration,
            new ContainerPathMapper($configuration),
            $projects,
            $configurationValidationLoader ?? new ProjectConfigurationValidationSnapshotLoader(new ConfigurationValidationRegistry()),
            $statuses ?? new ProjectIndexStatusRegistry(),
            $logger ?? new ServerLogger(null, new SensitiveDataRedactor()),
            $snapshotStore,
            $snapshotState,
            $releaseMetadataUrl,
        );
    }
}
