<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpObjectCreation
{
    /** @param list<PhpArgument> $arguments */
    public function __construct(
        public readonly string $className,
        public readonly array $arguments,
        public readonly ?string $enclosingMethod = null,
    ) {
    }

    public function argument(string|int $name): ?PhpArgument
    {
        if (\is_int($name)) {
            return $this->arguments[$name] ?? null;
        }

        foreach ($this->arguments as $argument) {
            if ($name === $argument->name) {
                return $argument;
            }
        }

        return null;
    }
}
