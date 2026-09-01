<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Parser\BalancedDelimiterMatcher;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;

final class RouteParameterKeyExtractor
{
    public function __construct(private readonly BalancedDelimiterMatcher $delimiters)
    {
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
        $keys = [];
        $depth = 0;
        $literalKey = null;
        $keyIsLiteral = true;
        $keyParsed = false;
        foreach (token_get_all('<?php '.$parameters) as $token) {
            if (\is_array($token)) {
                if (\T_ELLIPSIS === $token[0] && 0 === $depth) {
                    return null;
                }
                if (\in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    if (0 === $depth && !$keyParsed) {
                        $keyIsLiteral = false;
                    }
                    ++$depth;

                    continue;
                }
                if (0 !== $depth || \in_array($token[0], [\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                if (\T_DOUBLE_ARROW === $token[0] && !$keyParsed) {
                    if (!$keyIsLiteral || null === $literalKey) {
                        return null;
                    }
                    $quote = $literalKey[0];
                    $value = substr($literalKey, 1, -1);
                    $keys[] = "'" === $quote ? strtr($value, ['\\\\' => '\\', "\\'" => "'"]) : PhpStringLiteralDecoder::decodeDoubleQuoted($value);
                    $keyParsed = true;
                } elseif (!$keyParsed) {
                    if (\T_CONSTANT_ENCAPSED_STRING === $token[0] && null === $literalKey) {
                        $literalKey = $token[1];
                    } else {
                        $keyIsLiteral = false;
                    }
                }
                continue;
            }
            if (\in_array($token, ['(', '[', '{'], true)) {
                if (0 === $depth && !$keyParsed) {
                    $keyIsLiteral = false;
                }
                ++$depth;
            } elseif (\in_array($token, [')', ']', '}'], true)) {
                --$depth;
            } elseif (0 === $depth && ',' === $token) {
                $literalKey = null;
                $keyIsLiteral = true;
                $keyParsed = false;
            } elseif (0 === $depth && !$keyParsed) {
                $keyIsLiteral = false;
            }
        }

        return array_values(array_unique($keys));
    }
}
