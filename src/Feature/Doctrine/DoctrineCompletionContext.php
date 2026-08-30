<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Range;

final class DoctrineCompletionContext
{
    public function __construct(
        public readonly DoctrineCompletionKind $kind,
        public readonly ?string $entityClass,
        public readonly ?string $repositoryClass,
        public readonly string $prefix,
        public readonly Range $range,
    ) {
    }
}
