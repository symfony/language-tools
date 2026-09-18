<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Runtime\EnvironmentScopeResolver;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class EnvironmentScopes
{
    public static function resolver(string $environment = 'dev'): EnvironmentScopeResolver
    {
        $runtimeConfiguration = new RuntimeConfiguration();
        $runtimeConfiguration->configure(['environment' => $environment]);

        return new EnvironmentScopeResolver(ProjectPaths::resolver(), $runtimeConfiguration);
    }
}
