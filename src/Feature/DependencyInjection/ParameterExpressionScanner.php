<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class ParameterExpressionScanner
{
    /** Container parameter names are percent-delimited and never contain a percent sign or whitespace; `%%` is an escaped percent sign. */
    private const PATTERN = '/%%|%([^%\s]++)%/';

    /** @return list<ParameterExpression> */
    public function scan(string $text, int $baseOffset = 0): array
    {
        preg_match_all(self::PATTERN, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        $expressions = [];
        foreach ($matches as $match) {
            if (isset($match[1])) {
                $expressions[] = new ParameterExpression($match[1][0], $baseOffset + $match[1][1]);
            }
        }

        return $expressions;
    }
}
