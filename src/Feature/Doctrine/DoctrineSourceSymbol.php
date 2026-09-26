<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\LocatedSourceSymbolInterface;
use Symfony\Lsp\Index\NamedSourceSymbolInterface;

final class DoctrineSourceSymbol implements LocatedSourceSymbolInterface, NamedSourceSymbolInterface
{
    public function __construct(
        public readonly DoctrineSymbolKind $kind,
        public readonly string $name,
        public readonly ?string $owner,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $declaration,
    ) {
    }
}
