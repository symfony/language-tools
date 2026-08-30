<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigPhpSymbolReference
{
    public function __construct(
        public readonly string $className,
        public readonly ?string $memberName,
        public readonly string $uri,
        public readonly Range $range,
    ) {
    }
}
