<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Progress\ProgressReporterInterface;

final class CheckProgressReporter implements ProgressReporterInterface
{
    public function begin(string $title, string $message): ?string
    {
        return null;
    }

    public function end(?string $token, string $message): void
    {
    }
}
