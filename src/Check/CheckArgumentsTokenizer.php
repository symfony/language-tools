<?php

namespace Symfony\Lsp\Check;

final class CheckArgumentsTokenizer
{
    /** @param list<string> $arguments */
    public function tokenize(array $arguments): TokenizedCheckArguments
    {
        $tokens = [];
        $formats = [];
        $positionals = false;
        foreach ($arguments as $argument) {
            if ($positionals) {
                $tokens[] = new CheckArgumentToken('positional', $argument);
                continue;
            }
            if ('--' === $argument) {
                $tokens[] = new CheckArgumentToken('separator', $argument);
                $positionals = true;
                continue;
            }
            if (!str_starts_with($argument, '-')) {
                $tokens[] = new CheckArgumentToken('positional', $argument);
                continue;
            }
            if (1 === preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D', $argument, $match)) {
                $tokens[] = new CheckArgumentToken('option', $argument, $match[1], $match[2]);
                if ('format' === $match[1]) {
                    $formats[$match[2]] = true;
                }
                continue;
            }

            $tokens[] = new CheckArgumentToken('flag', $argument, $argument);
        }

        return new TokenizedCheckArguments(1 === \count($formats) ? array_key_first($formats) : 'human', $tokens);
    }
}
