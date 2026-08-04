<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Range;

final class DoctrineField
{
    public function __construct(
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly bool $association,
        private readonly ?string $type = null,
        private readonly ?string $targetEntity = null,
    ) {
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

    public function isAssociation(): bool
    {
        return $this->association;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function targetEntity(): ?string
    {
        return $this->targetEntity;
    }
}
