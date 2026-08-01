<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpArgument
{
    public function __construct(
        private readonly ?string $name,
        private readonly ?PhpStringLiteral $stringLiteral,
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
}
