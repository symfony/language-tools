<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class ParameterExpression
{
    public function __construct(
        public readonly string $name,
        public readonly int $nameStartOffset,
    ) {
    }

    public function startOffset(): int
    {
        return $this->nameStartOffset - 1;
    }

    public function text(): string
    {
        return '%'.$this->name.'%';
    }
}
