<?php

namespace Symfony\Lsp\Parser;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

/**
 * Matches a quoted string literal as the first argument of a named call.
 *
 * Single-quoted literals use PHP escaping and preserve unknown escapes.
 * Double-quoted literals only accept the escaped backslash and quote;
 * interpolation and other escape sequences are dynamic values and never match.
 * Twig sources must use TwigQuotedArgumentMatcher.
 */
class QuotedArgumentMatcher
{
    private const LITERAL = '(?:\'(?<single>(?:\\\\.|[^\'\\\\])+)\'|"(?<double>(?:\\\\[\\\\"]|[^"\\\\$])+)")';

    public function __construct(private readonly PositionConverter $converter)
    {
    }

    /**
     * @param list<string> $names
     *
     * @return list<QuotedArgument>
     */
    public function methodCalls(string $text, array $names): array
    {
        return $this->match($text, '/(?:->|::)(?<name>'.$this->alternation($names).')\s*\(\s*'.self::LITERAL.'(?=\s*[,\)])/s');
    }

    /**
     * @param list<string> $names
     *
     * @return list<QuotedArgument>
     */
    public function functionCalls(string $text, array $names): array
    {
        return $this->match($text, '/\b(?<name>'.$this->alternation($names).')\s*\(\s*'.self::LITERAL.'(?=\s*[,\)])/s');
    }

    /** @param list<string> $names */
    private function alternation(array $names): string
    {
        return implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $names));
    }

    /** @return list<QuotedArgument> */
    private function match(string $text, string $pattern): array
    {
        preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
        $arguments = [];
        foreach ($matches as $match) {
            $single = \is_string($match['single'][0] ?? null);
            $raw = $single ? $match['single'][0] : $match['double'][0];
            $offset = $single ? $match['single'][1] : $match['double'][1];
            $name = $match['name'][0];
            if (!\is_string($raw) || !\is_string($name)) {
                continue;
            }
            $value = $this->decode($raw, $single);
            $arguments[] = new QuotedArgument(
                $name,
                $match['name'][1],
                $value,
                $offset,
                \strlen($raw),
                new Range(
                    $this->converter->toPosition($text, $offset),
                    $this->converter->toPosition($text, $offset + \strlen($raw)),
                ),
            );
        }

        return $arguments;
    }

    protected function decode(string $raw, bool $single): string
    {
        return $single
            ? strtr($raw, ['\\\\' => '\\', "\\'" => "'"])
            : strtr($raw, ['\\\\' => '\\', '\\"' => '"']);
    }
}
