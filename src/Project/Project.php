<?php

namespace Symfony\Lsp\Project;

final class Project
{
    public function __construct(
        public readonly string $rootPath,
        public readonly string $rootUri,
    ) {
    }
}
