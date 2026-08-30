<?php

namespace Symfony\Lsp\Runtime;

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }
}
