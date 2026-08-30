<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpDiagnostic
{
    public function __construct(
        public readonly string $message,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {
    }
}
