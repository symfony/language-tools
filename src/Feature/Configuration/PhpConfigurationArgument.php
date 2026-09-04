<?php

namespace Symfony\Lsp\Feature\Configuration;

final class PhpConfigurationArgument
{
    public function __construct(
        public readonly string $source,
        public readonly bool $literal,
        public readonly mixed $value = null,
    ) {
    }
}
