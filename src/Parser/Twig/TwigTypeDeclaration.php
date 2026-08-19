<?php

namespace Symfony\Lsp\Parser\Twig;

final class TwigTypeDeclaration
{
    public function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly bool $optional,
        private readonly ?string $documentation,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function optional(): bool
    {
        return $this->optional;
    }

    public function documentation(): ?string
    {
        return $this->documentation;
    }
}
