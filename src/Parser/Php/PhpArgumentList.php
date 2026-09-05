<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpArgumentList
{
    use PhpArgumentAccess;

    /** @param list<PhpArgument> $arguments */
    public function __construct(public readonly array $arguments)
    {
    }
}
