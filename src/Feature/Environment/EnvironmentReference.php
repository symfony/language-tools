<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;

final class EnvironmentReference implements RangedSourceSymbolInterface
{
    /** @param list<string> $processors */
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly array $processors,
    ) {
    }
}
