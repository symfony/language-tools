<?php

namespace Symfony\Lsp\Check;

final class CheckExecution
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr = '',
    ) {
    }
}
