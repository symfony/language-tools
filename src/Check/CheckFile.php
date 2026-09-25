<?php

namespace Symfony\Lsp\Check;

final class CheckFile
{
    public function __construct(
        public readonly CheckProject $project,
        public readonly string $path,
        public readonly string $projectPath,
        public readonly string $workspacePath,
        public readonly string $uri,
        public readonly string $languageId,
        public readonly bool $excluded,
    ) {
    }
}
