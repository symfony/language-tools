<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Project\AnalysisSettingsRegistry;
use Symfony\Lsp\Project\ProjectAnalysisSettings;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class RuntimeSettings
{
    public static function registry(ProjectAnalysisSettings $workspace = new ProjectAnalysisSettings()): AnalysisSettingsRegistry
    {
        $registry = new AnalysisSettingsRegistry();
        $registry->configureWorkspace($workspace);

        return $registry;
    }

    /** @param array<array-key, mixed> $defaultPhpCommand */
    public static function configuration(ProjectAnalysisSettings $workspace = new ProjectAnalysisSettings(), array $defaultPhpCommand = ['php']): RuntimeConfiguration
    {
        return new RuntimeConfiguration(self::registry($workspace), defaultPhpCommand: $defaultPhpCommand);
    }
}
