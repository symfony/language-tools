<?php

namespace Symfony\Lsp\Project;

final class GitignorePattern
{
    private function __construct(
        public readonly bool $negated,
        private readonly bool $directoryOnly,
        private readonly string $regex,
    ) {
    }

    public static function compile(string $line): ?self
    {
        if (str_starts_with($line, '#')) {
            return null;
        }

        $line = (string) preg_replace('~(?<!\\\\)[ \t]+$~', '', $line);

        $negated = str_starts_with($line, '!');
        if ($negated) {
            $line = substr($line, 1);
        }

        $directoryOnly = str_ends_with($line, '/');
        if ($directoryOnly) {
            $line = substr($line, 0, -1);
        }

        $anchored = str_contains($line, '/');
        if (str_starts_with($line, '/')) {
            $line = substr($line, 1);
        }

        if ('' === $line) {
            return null;
        }

        $prefix = $anchored ? '' : '(?:[^/]+/)*';

        return new self($negated, $directoryOnly, '~^'.$prefix.self::toRegex($line).'$~s');
    }

    public function matches(string $relativePath, bool $isDirectory): bool
    {
        if ($this->directoryOnly && !$isDirectory) {
            return false;
        }

        return 1 === preg_match($this->regex, $relativePath);
    }

    private static function toRegex(string $pattern): string
    {
        $regex = '';
        $length = \strlen($pattern);
        for ($offset = 0; $offset < $length; ++$offset) {
            $character = $pattern[$offset];

            if ('\\' === $character && $offset + 1 < $length) {
                $regex .= preg_quote($pattern[++$offset], '~');

                continue;
            }

            if ('*' === $character && '*' === ($pattern[$offset + 1] ?? '')) {
                while ('*' === ($pattern[$offset + 1] ?? '')) {
                    ++$offset;
                }

                if ('' === $regex || str_ends_with($regex, '/')) {
                    if ('/' === ($pattern[$offset + 1] ?? '')) {
                        ++$offset;
                        $regex .= '(?:[^/]+/)*';

                        continue;
                    }
                    if ($offset + 1 === $length) {
                        $regex .= '' === $regex ? '.*' : '.+';

                        continue;
                    }
                }
            }

            if ('*' === $character) {
                $regex .= '[^/]*';

                continue;
            }

            if ('?' === $character) {
                $regex .= '[^/]';

                continue;
            }

            if ('[' === $character && null !== $class = self::characterClass($pattern, $offset)) {
                $regex .= $class[0];
                $offset = $class[1];

                continue;
            }

            $regex .= preg_quote($character, '~');
        }

        return $regex;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function characterClass(string $pattern, int $offset): ?array
    {
        $end = strpos($pattern, ']', $offset + 1);
        if (false === $end) {
            return null;
        }

        $class = substr($pattern, $offset + 1, $end - $offset - 1);
        $complemented = str_starts_with($class, '!') || str_starts_with($class, '^');
        if ($complemented) {
            $class = substr($class, 1);
        }

        if ('' === $class || false !== strpbrk($class, '\\~[')) {
            return null;
        }

        return ['['.($complemented ? '^' : '').$class.']', $end];
    }
}
