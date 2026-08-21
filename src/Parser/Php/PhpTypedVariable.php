<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpTypedVariable
{
    /** @param list<string> $types */
    public function __construct(private readonly string $name, private readonly array $types)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function types(): array
    {
        return $this->types;
    }
}
