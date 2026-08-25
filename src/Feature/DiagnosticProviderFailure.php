<?php

namespace Symfony\Lsp\Feature;

final class DiagnosticProviderFailure
{
    public function __construct(
        public readonly string $provider,
        public readonly \Throwable $error,
    ) {
    }
}
