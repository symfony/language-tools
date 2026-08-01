<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpAttribute
{
    /**
     * @param list<PhpArgument> $arguments
     */
    public function __construct(
        private readonly string $name,
        private readonly array $arguments,
    ) {
    }

    public function name(): string
    {
        return $this->name;
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
