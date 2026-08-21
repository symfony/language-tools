<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpCallable
{
    public function __construct(
        private readonly string $className,
        private readonly string $method,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function method(): string
    {
        return $this->method;
    }
}
