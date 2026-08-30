<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Document\Range;

final class DoctrineField
{
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $association,
        public readonly ?string $type = null,
        public readonly ?string $targetEntity = null,
    ) {
    }
}
