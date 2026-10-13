<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class PhpTargetArgument
{
    /** @param list<string> $types */
    public function __construct(
        public readonly int $valueStartOffset,
        public readonly string $value,
        public readonly ?int $literalStartOffset,
        public readonly ?int $literalEndOffset,
        public readonly ?string $name,
        public readonly array $types,
        public readonly ?string $parameterName,
    ) {
    }

    public function cursorOffset(): int
    {
        return $this->valueStartOffset + \strlen($this->value);
    }
}
