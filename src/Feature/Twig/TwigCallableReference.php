<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableReference
{
    public function __construct(
        private readonly TwigCallableKind $kind,
        private readonly string $name,
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
}
