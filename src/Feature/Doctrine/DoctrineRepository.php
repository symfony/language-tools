<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Range;

final class DoctrineRepository
{
    public function __construct(
        public readonly string $className,
        public readonly string $entityClass,
        public readonly string $uri,
        public readonly Range $range,
    ) {
    }
}
