<?php

namespace Symfony\Lsp\Feature\Security;

use Symfony\Lsp\Document\Range;

final class SecurityCompletionContext
{
    public function __construct(
        public readonly SecuritySymbolKind $kind,
        public readonly string $prefix,
        public readonly Range $range,
    ) {
    }
}
