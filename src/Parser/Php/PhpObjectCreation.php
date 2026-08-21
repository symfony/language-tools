<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpObjectCreation
{
    /** @param list<PhpArgument> $arguments */
    public function __construct(
        private readonly string $className,
        private readonly array $arguments,
        private readonly ?string $enclosingMethod = null,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function enclosingMethod(): ?string
    {
        return $this->enclosingMethod;
    }

    /** @return list<PhpArgument> */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function argument(string|int $name): ?PhpArgument
    {
        if (\is_int($name)) {
            return $this->arguments[$name] ?? null;
        }

        foreach ($this->arguments as $argument) {
            if ($name === $argument->name()) {
                return $argument;
            }
        }

        return null;
    }
}
