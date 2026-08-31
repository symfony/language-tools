<?php

namespace Symfony\Lsp\Check;

final class CheckDiagnosticOccurrence
{
    public function __construct(
        public readonly CheckDiagnostic $diagnostic,
        public readonly int $number,
    ) {
    }
}
