<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpArgument
{
    public function __construct(
        private readonly ?string $name,
        private readonly ?PhpStringLiteral $stringLiteral,
        private readonly ?PhpCallable $callable = null,
        private readonly ?string $expression = null,
    ) {
    }

    public function name(): ?string
    {
        return $this->name;
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
}
