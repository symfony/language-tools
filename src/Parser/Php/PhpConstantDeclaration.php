<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpConstantDeclaration
{
    public function __construct(
        public readonly PhpConstantKind $kind,
        public readonly string $className,
        public readonly string $name,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
        public readonly string $signature,
        public readonly ?string $description,
        public readonly bool $public,
    ) {
    }
}
