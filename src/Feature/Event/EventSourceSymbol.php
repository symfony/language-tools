<?php

namespace Symfony\Lsp\Feature\Event;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\LocatedSourceSymbolInterface;
use Symfony\Lsp\Index\NamedSourceSymbolInterface;

final class EventSourceSymbol implements LocatedSourceSymbolInterface, NamedSourceSymbolInterface
{
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $declaration,
    ) {
    }
}
