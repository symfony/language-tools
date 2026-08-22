<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ProjectConfiguration
{
    /**
     * @param list<string> $probeRoots
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
        public readonly array $probeRoots = ProbeFinder::DEFAULT_ROOTS,
        public readonly int $probesPerCategory = 1,
    ) {
    }
}
