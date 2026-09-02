<?php

namespace Symfony\Lsp\Feature\Environment;

final class EnvironmentExpression
{
    /** @param list<string> $processorChain */
    public function __construct(
        public readonly string $variableName,
        public readonly array $processorChain,
        public readonly EnvironmentExpressionRange $variableRange,
    ) {
    }
}
