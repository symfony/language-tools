<?php

namespace Symfony\Lsp\Feature\Environment;

final class EnvironmentExpressionSegment
{
    public function __construct(
        public readonly string $value,
        public readonly EnvironmentExpressionRange $range,
    ) {
    }
}
