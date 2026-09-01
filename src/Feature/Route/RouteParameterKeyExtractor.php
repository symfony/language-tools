<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpLiteralArrayKeyParser;

final class RouteParameterKeyExtractor
{
    public function __construct(
        private readonly BalancedDelimiterMatcher $delimiters,
        private readonly PhpLiteralArrayKeyParser $arrayKeys,
    ) {
    }

    /**
     * @return list<string>|null
     */
    public function extract(string $afterRouteName): ?array
    {
        if (preg_match('/^\s*\)/', $afterRouteName)) {
            return [];
        }

        if (null === $parameters = $this->parameterArray($afterRouteName)) {
            return null;
        }

        return $this->literalParameterKeys($parameters);
    }

    private function parameterArray(string $afterRouteName): ?string
    {
        if (!preg_match('/^\s*,\s*\[/', $afterRouteName, $match)) {
            return null;
        }
        $open = strpos($afterRouteName, '[', \strlen($match[0]) - 1);
        if (false === $open || null === $close = $this->delimiters->matching($afterRouteName, $open, '[', ']')) {
            return null;
        }
        $tail = ltrim(substr($afterRouteName, $close + 1));
        if ('' === $tail || !\in_array($tail[0], [',', ')'], true)) {
            return null;
        }

        return substr($afterRouteName, $open + 1, $close - $open - 1);
    }

    /** @return list<string>|null */
    private function literalParameterKeys(string $parameters): ?array
    {
        $keys = $this->arrayKeys->parse($parameters, allowNestedUnpacking: true);

        return null === $keys ? null : array_values(array_unique($keys));
    }
}
