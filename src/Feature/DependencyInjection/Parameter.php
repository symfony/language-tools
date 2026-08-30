<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class Parameter
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $deprecation,
    ) {
    }
}
