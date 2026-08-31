<?php

namespace Symfony\Lsp\Feature\Environment;

final class EnvironmentExpression
{
    /**
     * @param list<string>                       $processorChain
     * @param list<EnvironmentExpressionSegment> $argumentSegments
     */
    public function __construct(
        public readonly string $variableName,
        public readonly array $processorChain,
        public readonly array $argumentSegments,
        public readonly EnvironmentExpressionRange $range,
        public readonly EnvironmentExpressionRange $variableRange,
    ) {
    }
}
