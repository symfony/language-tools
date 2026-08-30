<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class Service
{
    /**
     * @param list<string> $tags
     * @param list<string> $autowiringTypes
     * @param list<string> $decorationStack
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $className,
        public readonly ?string $alias,
        public readonly ?bool $public,
        public readonly ?bool $lazy,
        public readonly ?string $deprecation,
        public readonly array $tags,
        public readonly ?string $decorates,
        public readonly array $autowiringTypes,
        public readonly array $decorationStack = [],
    ) {
    }
}
