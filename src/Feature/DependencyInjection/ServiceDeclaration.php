<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\LocatedSourceSymbolInterface;

final class ServiceDeclaration implements LocatedSourceSymbolInterface
{
    /** @param list<string> $tags */
    public function __construct(
        public readonly string $id,
        public readonly string $uri,
        public readonly Range $range,
        public readonly ?string $className = null,
        public readonly ?string $alias = null,
        public readonly ?string $decorates = null,
        public readonly array $tags = [],
        public readonly ?string $environment = null,
    ) {
    }
}
