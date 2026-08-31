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
        $bodyOffset = $sourceOffset + 5;
        $segments = [];
        $segmentOffset = 0;
        foreach (explode(':', $body) as $value) {
            $startByte = $bodyOffset + $segmentOffset;
            $segments[] = new EnvironmentExpressionSegment(
                $value,
                new EnvironmentExpressionRange($startByte, $startByte + \strlen($value)),
            );
            $segmentOffset += \strlen($value) + 1;
        }

        $variable = array_pop($segments);
        if (null === $variable || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $variable->value)) {
            return null;
        }

        return new EnvironmentExpression(
            $variable->value,
            array_map(static fn (EnvironmentExpressionSegment $segment): string => $segment->value, $segments),
            $segments,
            new EnvironmentExpressionRange($sourceOffset, $sourceOffset + \strlen($expression)),
            $variable->range,
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
