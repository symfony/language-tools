<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpMethodDeclaration
{
    /**
     * @param list<PhpAttribute> $attributes
     * @param list<PhpParameter> $parameters
     */
    public function __construct(
        public readonly string $className,
        public readonly string $name,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
        public readonly string $signature,
        public readonly ?string $description,
        public readonly array $attributes = [],
        public readonly array $parameters = [],
        public readonly ?int $bodyStartOffset = null,
        public readonly ?int $bodyEndOffset = null,
        public readonly bool $public = true,
    ) {
    }
}
