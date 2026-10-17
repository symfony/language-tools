<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class AutowiringVariant
{
    public function __construct(
        public readonly string $name,
        public readonly string $aliasId,
        public readonly string $serviceId,
    ) {
    }
}
