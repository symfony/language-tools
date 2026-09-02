<?php

namespace Symfony\Lsp\Feature\Environment;

final class EnvironmentExpressionParser
{
    public function parse(string $expression, int $sourceOffset = 0): ?EnvironmentExpression
    {
        if (1 !== preg_match('/\A%env\(([^)%]+)\)%\z/', $expression, $match)) {
            return null;
        }

        $body = $match[1];
        $segments = explode(':', $body);
        $variableName = array_pop($segments);
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $variableName)) {
            return null;
        }
        $variableStart = $sourceOffset + 5 + \strlen($body) - \strlen($variableName);

        return new EnvironmentExpression(
            $variableName,
            $segments,
            new EnvironmentExpressionRange($variableStart, $variableStart + \strlen($variableName)),
        );
    }

    /** @return list<EnvironmentExpression> */
    public function parseAll(string $source, int $sourceOffset = 0): array
    {
        preg_match_all('/%env\([^\)\r\n]*\)%/', $source, $matches, \PREG_OFFSET_CAPTURE);
        $expressions = [];
        foreach ($matches[0] as [$expression, $offset]) {
            $parsed = $this->parse($expression, $sourceOffset + $offset);
            if (null !== $parsed) {
                $expressions[] = $parsed;
            }
        }

        return $expressions;
    }
}
