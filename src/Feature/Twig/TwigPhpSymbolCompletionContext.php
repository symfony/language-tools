<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigPhpSymbolCompletionContext
{
    public function __construct(
        public readonly TwigPhpSymbolCompletionKind $kind,
        public readonly string $prefix,
        public readonly Range $range,
        public readonly ?string $className = null,
    ) {
    }
}
