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

    public function positionalArgument(int $position): ?PhpArgument
    {
        $argument = $this->arguments[$position] ?? null;

        return null === $argument?->name ? $argument : null;
    }
}
