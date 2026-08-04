<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Range;

final class DoctrineCompletionContext
{
    public function __construct(
        private readonly ?string $entityClass,
        private readonly ?string $repositoryClass,
        private readonly string $prefix,
        private readonly Range $range,
    ) {
    }

    public function entityClass(): ?string
    {
        return $this->entityClass;
    }

    public function repositoryClass(): ?string
    {
        return $this->repositoryClass;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function range(): Range
    {
        return $this->range;
    }
}
