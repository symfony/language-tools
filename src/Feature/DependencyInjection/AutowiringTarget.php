<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class AutowiringTarget
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $aliasId,
        public readonly ?string $serviceId,
        public readonly ?string $className,
        public readonly bool $hidden,
    ) {
    }
}
