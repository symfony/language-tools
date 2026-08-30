<?php

namespace Symfony\Lsp\Feature\Messenger;

use Symfony\Lsp\Document\Range;

final class MessengerSourceSymbol
{
    public function __construct(
        public readonly MessengerSymbolKind $kind,
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $declaration,
    ) {
    }
}
