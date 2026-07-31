<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Progress\ProgressReporterInterface;

final class NullProgressReporter implements ProgressReporterInterface
{
    public function begin(string $title, string $message): ?string
    {
        return null;
    }

    public function end(?string $token, string $message): void
    {
    }
}
