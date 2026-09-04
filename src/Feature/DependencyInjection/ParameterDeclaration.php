<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Range;

final class ParameterDeclaration
{
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly ?string $environment = null,
    ) {
    }
}
