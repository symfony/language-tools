<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigPhpSymbolDeclaration
{
    public function __construct(
        public readonly TwigPhpSymbolKind $kind,
        public readonly string $className,
        public readonly ?string $memberName,
        public readonly string $uri,
        public readonly Range $range,
        public readonly string $signature,
        public readonly ?string $description,
        public readonly bool $public,
    ) {
    }
}
