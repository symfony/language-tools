<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpPropertyDeclaration
{
    /** @param list<string> $types */
    public function __construct(
        public readonly string $className,
        public readonly string $name,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
        public readonly string $signature,
        public readonly ?string $description,
        public readonly array $types,
        public readonly string $visibility,
        public readonly bool $promoted,
    ) {
    }

    public function isPublic(): bool
    {
        return 'public' === $this->visibility;
    }
}
