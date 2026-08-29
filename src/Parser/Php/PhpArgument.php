<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpArgument
{
    public function __construct(
        private readonly ?string $name,
        private readonly ?int $nameStartOffset,
        private readonly ?int $nameEndOffset,
        private readonly ?PhpStringLiteral $stringLiteral,
        private readonly ?PhpCallable $callable,
        private readonly ?string $expression,
        private readonly int $startOffset,
        private readonly int $endOffset,
        private readonly ?int $expressionStartOffset,
        private readonly ?int $expressionEndOffset,
    ) {
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function nameStartOffset(): ?int
    {
        return $this->nameStartOffset;
    }

    public function nameEndOffset(): ?int
    {
        return $this->nameEndOffset;
    }

    public function stringLiteral(): ?PhpStringLiteral
    {
        return $this->stringLiteral;
    }

    public function callable(): ?PhpCallable
    {
        return $this->callable;
    }

    public function expression(): ?string
    {
        return $this->expression;
    }

    public function startOffset(): int
    {
        return $this->startOffset;
    }

    public function endOffset(): int
    {
        return $this->endOffset;
    }

    public function expressionStartOffset(): ?int
    {
        return $this->expressionStartOffset;
    }

    public function expressionEndOffset(): ?int
    {
        return $this->expressionEndOffset;
    }
}
