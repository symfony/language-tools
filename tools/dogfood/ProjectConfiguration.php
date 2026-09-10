<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ProjectConfiguration
{
    /**
     * @param list<string>            $allowPlugins
     * @param list<string>            $ignorePlatformRequirements
     * @param list<string>            $setupChanges               tracked files the project's own setup scripts are expected to change
     * @param array<string, string>   $environmentVariables
     * @param 'runtime'|'source-only' $analysisMode
     * @param int                     $checkCpuBudget             CPU seconds the whole-project check process tree may use
     * @param int                     $coldRunCpuBudget           CPU seconds the cold server run process tree may use
     */
    public function __construct(
        public readonly string $name,
        public readonly string $repository,
        public readonly string $revision,
        public readonly ?string $directory,
        public readonly string $environment,
        public readonly string $setup,
        public readonly bool $ci,
        public readonly int $indexTimeout,
        public readonly int $requestTimeout = 10,
        public readonly ?string $lockFile = null,
        public readonly array $allowPlugins = [],
        public readonly array $ignorePlatformRequirements = [],
        public readonly array $setupChanges = [],
        public readonly array $environmentVariables = [],
        public readonly string $scenarioFile = '',
        public readonly string $analysisMode = 'runtime',
        public readonly int $checkCpuBudget = 60,
        public readonly int $coldRunCpuBudget = 120,
    ) {
    }
}
