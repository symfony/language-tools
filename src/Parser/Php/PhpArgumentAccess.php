<?php

namespace Symfony\Lsp\Parser\Php;

trait PhpArgumentAccess
{
    public function argument(string $name): ?PhpArgument
    {
        foreach ($this->arguments as $argument) {
            if ($name === $argument->name) {
                return $argument;
            }
        }

        return null;
    }

    public function namedOrPositionalArgument(string $name, int $position): ?PhpArgument
    {
        return $this->argument($name) ?? $this->positionalArgument($position);
    }

    public function positionalArgument(int $position): ?PhpArgument
    {
        $argument = $this->arguments[$position] ?? null;
        if (null === $argument || null !== $argument->name || $argument->unpacked) {
            return null;
        }

        return $argument;
    }
}
