<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpMethodDeclaration
{
    /** @param list<PhpAttribute> $attributes */
    public function __construct(
        public readonly string $className,
        public readonly string $name,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
        public readonly string $signature,
        public readonly ?string $description,
        public readonly array $attributes = [],
        public readonly ?string $firstParameterType = null,
        public readonly bool $firstParameterVariadic = false,
        public readonly bool $variadic = false,
        public readonly bool $public = true,
    ) {
    }
}
