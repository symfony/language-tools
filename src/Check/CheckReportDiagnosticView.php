<?php

namespace Symfony\Lsp\Check;

final class CheckReportDiagnosticView
{
    public function __construct(
        public readonly CheckDiagnostic $diagnostic,
        public readonly int $occurrence,
        public readonly string $feature,
        public readonly ?string $environment,
        public readonly ?string $analysisMode,
    ) {
    }
}
