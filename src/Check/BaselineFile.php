<?php

namespace Symfony\Lsp\Check;

final class BaselineFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $workspacePath,
        public readonly string $displayPath,
    ) {
    }
}
