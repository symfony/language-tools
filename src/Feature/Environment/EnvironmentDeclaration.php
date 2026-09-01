<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\RangedSourceSymbolInterface;

final class EnvironmentDeclaration implements RangedSourceSymbolInterface
{
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $hasDefault,
    ) {
    }
}
