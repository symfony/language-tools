<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Document\Range;

final class EnvironmentReference
{
    /** @param list<string> $processors */
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly array $processors,
    ) {
    }
}
