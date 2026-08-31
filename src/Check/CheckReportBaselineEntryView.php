<?php

namespace Symfony\Lsp\Check;

final class CheckReportBaselineEntryView
{
    public function __construct(
        public readonly BaselineEntry $entry,
        public readonly string $workspacePath,
    ) {
    }
}
