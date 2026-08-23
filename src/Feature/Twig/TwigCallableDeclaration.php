<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigCallableDeclaration
{
    public function __construct(
        private readonly TwigCallableKind $kind,
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly ?string $className = null,
        private readonly ?string $method = null,
        private readonly bool $needsEnvironment = false,
        private readonly bool $needsContext = false,
        private readonly bool $variadic = false,
        private readonly bool $optionsKnown = true,
    ) {
    }

    public function kind(): TwigCallableKind
    {
        return $this->kind;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }

    public function className(): ?string
    {
        return $this->className;
    }

    public function method(): ?string
    {
        return $this->method;
    }

    public function needsEnvironment(): bool
    {
        return $this->needsEnvironment;
    }

    public function needsContext(): bool
    {
        return $this->needsContext;
    }

    public function isVariadic(): bool
    {
        return $this->variadic;
    }

    public function optionsKnown(): bool
    {
        return $this->optionsKnown;
    }
}
