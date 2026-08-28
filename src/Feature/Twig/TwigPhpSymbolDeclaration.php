<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigPhpSymbolDeclaration
{
    public function __construct(
        private readonly TwigPhpSymbolKind $kind,
        private readonly string $className,
        private readonly ?string $memberName,
        private readonly string $uri,
        private readonly Range $range,
        private readonly string $signature,
        private readonly ?string $description,
        private readonly bool $public,
    ) {
    }

    public function kind(): TwigPhpSymbolKind
    {
        return $this->kind;
    }

    public function className(): string
    {
        return $this->className;
    }

    public function memberName(): ?string
    {
        return $this->memberName;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }

    public function signature(): string
    {
        return $this->signature;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }
}
