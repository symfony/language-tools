<?php

namespace Symfony\Lsp\Progress;

interface ProgressReporterInterface
{
    public function begin(string $title, string $message): ?string;

    public function end(?string $token, string $message): void;
}
