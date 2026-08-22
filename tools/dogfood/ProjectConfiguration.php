<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ProjectConfiguration
{
    public function __construct(
        public readonly string $name,
        public readonly string $repository,
        public readonly string $revision,
        public readonly ?string $directory,
        public readonly string $environment,
        public readonly string $setup,
        public readonly bool $ci,
        public readonly int $indexTimeout,
    ) {
    }
}
