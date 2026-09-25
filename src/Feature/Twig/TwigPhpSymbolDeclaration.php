<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\LocatedSourceSymbolInterface;

final class TwigPhpSymbolDeclaration implements LocatedSourceSymbolInterface
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
