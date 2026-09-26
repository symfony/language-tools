<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Project\ProjectAnalysisSettings;
use Symfony\Lsp\Runtime\EnvironmentScopeResolver;

final class EnvironmentScopes
{
    public static function resolver(string $environment = 'dev'): EnvironmentScopeResolver
    {
        return new EnvironmentScopeResolver(
            ProjectPaths::resolver(),
            RuntimeSettings::configuration(new ProjectAnalysisSettings(environment: $environment)),
        );
    }
}
