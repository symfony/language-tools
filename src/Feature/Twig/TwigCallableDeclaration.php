<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigCallableDeclaration
{
    // Defaults keep older cached payloads readable
    private bool $needsCharset = false;
    private bool $needsContext = false;
    private bool $needsEnvironment = false;
    private bool $needsIsSandboxed = false;
    private bool $optionsKnown = true;
    private bool $variadic = false;

    public function __construct(
        private readonly TwigCallableKind $kind,
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly ?string $className = null,
        private readonly ?string $method = null,
        bool $needsEnvironment = false,
        bool $needsContext = false,
        bool $variadic = false,
        bool $optionsKnown = true,
        bool $needsCharset = false,
        bool $needsIsSandboxed = false,
    ) {
        $this->needsCharset = $needsCharset;
        $this->needsContext = $needsContext;
        $this->needsEnvironment = $needsEnvironment;
        $this->needsIsSandboxed = $needsIsSandboxed;
        $this->optionsKnown = $optionsKnown;
        $this->variadic = $variadic;
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

    public function needsCharset(): bool
    {
        return $this->needsCharset;
    }

    public function needsEnvironment(): bool
    {
        return $this->needsEnvironment;
    }

    public function needsContext(): bool
    {
        return $this->needsContext;
    }

    public function needsIsSandboxed(): bool
    {
        return $this->needsIsSandboxed;
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
