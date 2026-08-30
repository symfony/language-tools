<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpObjectCreation
{
    use PhpArgumentAccess;

    /** @param list<PhpArgument> $arguments */
    public function __construct(
        public readonly string $className,
        public readonly array $arguments,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly int $classNameStartOffset,
        public readonly int $classNameEndOffset,
        public readonly ?string $enclosingMethod = null,
    ) {
    }
}
