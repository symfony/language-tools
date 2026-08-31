<?php

namespace Symfony\Lsp\Check;

final class CheckReportSummary
{
    public function __construct(
        public readonly int $diagnostics,
        public readonly int $active,
        public readonly int $matched,
        public readonly int $stale,
        public readonly int $blocking,
    ) {
    }

    /** @return array{diagnostics: int, active: int, matched: int, stale: int, blocking: int} */
    public function toArray(): array
    {
        return [
            'diagnostics' => $this->diagnostics,
            'active' => $this->active,
            'matched' => $this->matched,
            'stale' => $this->stale,
            'blocking' => $this->blocking,
        ];
    }
}
