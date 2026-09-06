<?php

namespace Symfony\Lsp\Tools;

final class DiagnosticParseCounter
{
    public int $calls = 0;
    public int $bytes = 0;

    public function record(string $source): void
    {
        ++$this->calls;
        $this->bytes += \strlen($source);
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->bytes = 0;
    }
}
