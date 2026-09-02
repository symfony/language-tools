<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Finder\Glob;

final class GlobPatternCompiler
{
    public function compile(string $pattern): string
    {
        $pattern = str_replace('\\', '/', $pattern);
        $compiled = '';
        $length = \strlen($pattern);
        for ($index = 0; $index < $length; ++$index) {
            if ('*' !== $pattern[$index] || '*' !== ($pattern[$index + 1] ?? null)) {
                $compiled .= $pattern[$index];

                continue;
            }

            $previous = 0 === $index ? null : $pattern[$index - 1];
            $next = $pattern[$index + 2] ?? null;
            $compiled .= (0 === $index && '/' === $next) || ('/' === $previous && (null === $next || '/' === $next))
                ? '**'
                : "\0";
            ++$index;
        }

        return str_replace("\0", '.*', Glob::toRegex($compiled, false, true, '~'));
    }
}
