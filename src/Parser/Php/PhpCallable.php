<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpCallable
{
    public function __construct(
        public readonly string $className,
        public readonly string $method,
    ) {
    }
}
