<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\Range;

final class EnvironmentDeclaration
{
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly bool $hasDefault,
    ) {
    }
}
