<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class PhpTargetArgument
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly int $valueStartOffset,
        public readonly string $prefix,
    ) {
    }
}
