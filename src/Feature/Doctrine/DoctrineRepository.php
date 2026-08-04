<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Range;

final class DoctrineRepository
{
    public function __construct(
        private readonly string $className,
        private readonly string $entityClass,
        private readonly string $uri,
        private readonly Range $range,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function entityClass(): string
    {
        return $this->entityClass;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }
}
