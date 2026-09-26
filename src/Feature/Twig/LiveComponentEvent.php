<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\LocatedSourceSymbolInterface;
use Symfony\Lsp\Index\NamedSourceSymbolInterface;

final class LiveComponentEvent implements LocatedSourceSymbolInterface, NamedSourceSymbolInterface
{
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $declaration,
        public readonly ?string $component = null,
        public readonly ?string $action = null,
    ) {
    }
}
