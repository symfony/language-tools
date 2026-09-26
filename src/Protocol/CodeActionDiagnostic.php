<?php

namespace Symfony\Lsp\Protocol;

use Symfony\Lsp\Document\Range;

/** A diagnostic of a code action request, as the client reported it back. */
final class CodeActionDiagnostic
{
    /** @param array<array-key, mixed> $diagnostic */
    public function __construct(
        public readonly string $code,
        public readonly Range $range,
        public readonly array $diagnostic,
    ) {
    }
}
