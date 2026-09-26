<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\LocatedSourceSymbolInterface;

final class ParameterDeclaration implements LocatedSourceSymbolInterface
{
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly ?string $environment = null,
    ) {
    }
}
