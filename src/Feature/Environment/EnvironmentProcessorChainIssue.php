<?php

namespace Symfony\Lsp\Feature\Environment;

final class EnvironmentProcessorChainIssue
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {
    }
}
