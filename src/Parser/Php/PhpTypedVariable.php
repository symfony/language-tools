<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpTypedVariable
{
    /** @param list<string> $types */
    public function __construct(
        public readonly string $name,
        public readonly array $types,
        public readonly PhpTypedVariableKind $kind,
        public readonly ?string $className,
        public readonly ?string $methodName,
        public readonly ?int $scopeStartOffset,
        public readonly ?int $scopeEndOffset,
        public readonly int $nameStartOffset,
        public readonly int $nameEndOffset,
    ) {
    }
}
