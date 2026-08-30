<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Range;

final class PhpClassDeclaration
{
    public function __construct(
        public readonly string $className,
        public readonly string $uri,
        public readonly Range $range,
        public readonly ?string $parentClassName = null,
    ) {
    }
}
