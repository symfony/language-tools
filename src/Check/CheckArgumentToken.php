<?php

namespace Symfony\Lsp\Check;

final class CheckArgumentToken
{
    public function __construct(
        public readonly string $kind,
        public readonly string $raw,
        public readonly ?string $name = null,
        public readonly ?string $value = null,
    ) {
    }
}
