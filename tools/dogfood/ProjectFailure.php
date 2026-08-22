<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ProjectFailure
{
    public function __construct(
        public readonly string $layer,
        public readonly string $message,
    ) {
    }
}
