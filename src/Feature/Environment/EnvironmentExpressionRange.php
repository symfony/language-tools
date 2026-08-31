<?php

namespace Symfony\Lsp\Feature\Environment;

final class EnvironmentExpressionRange
{
    public function __construct(
        public readonly int $startByte,
        public readonly int $endByte,
    ) {
    }
}
