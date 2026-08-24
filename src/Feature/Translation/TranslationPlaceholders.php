<?php

namespace Symfony\Lsp\Feature\Translation;

final class TranslationPlaceholders
{
    /**
     * Braces are only placeholders in ICU catalogs; in plain catalogs they
     * are literal text, such as prose documenting a template syntax.
     *
     * @return list<string>
     */
    public static function extract(string $message, bool $icu = false): array
    {
        preg_match_all('/%([^%\s]+)%/', $message, $percentMatches);
        $placeholders = array_values(array_filter($percentMatches[1], 'is_string'));
        if ($icu) {
            $offset = 0;
            array_push($placeholders, ...self::icuArguments($message, $offset));
        }
        $placeholders = array_values(array_unique($placeholders));
        sort($placeholders);

        return $placeholders;
    }

    /** @return list<string> */
    private static function icuArguments(string $message, int &$offset, bool $nested = false): array
    {
        $arguments = [];
        $length = \strlen($message);
        while ($offset < $length) {
            $character = $message[$offset++];
            if ("'" === $character && \in_array($message[$offset] ?? null, ["'", '{', '}', '#'], true)) {
                self::skipQuoted($message, $offset);

                continue;
            }
            if ('}' === $character && $nested) {
                break;
            }
            if ('{' === $character) {
                array_push($arguments, ...self::icuArgument($message, $offset));
            }
        }

        return $arguments;
    }

    /** @return list<string> */
    private static function icuArgument(string $message, int &$offset): array
    {
        $length = \strlen($message);
        while ($offset < $length && ctype_space($message[$offset])) {
            ++$offset;
        }
        $start = $offset;
        if ($offset >= $length || 1 !== preg_match('/[A-Za-z_]/A', $message, $match, 0, $offset)) {
            return self::icuArguments($message, $offset, true);
        }
        ++$offset;
        while ($offset < $length && 1 === preg_match('/[A-Za-z0-9_]/A', $message, $match, 0, $offset)) {
            ++$offset;
        }
        $name = substr($message, $start, $offset - $start);
        while ($offset < $length && ctype_space($message[$offset])) {
            ++$offset;
        }
        if ('}' === ($message[$offset] ?? null)) {
            ++$offset;

            return [$name];
        }
        if (',' !== ($message[$offset] ?? null)) {
            return self::icuArguments($message, $offset, true);
        }
        ++$offset;
        $typeStart = $offset;
        while ($offset < $length && !\in_array($message[$offset], [',', '}'], true)) {
            ++$offset;
        }
        $type = strtolower(trim(substr($message, $typeStart, $offset - $typeStart)));
        $types = ['date', 'duration', 'number', 'ordinal', 'plural', 'select', 'selectordinal', 'spellout', 'time'];
        if (!\in_array($type, $types, true)) {
            return self::icuArguments($message, $offset, true);
        }
        $arguments = [$name];
        if (\in_array($type, ['plural', 'select', 'selectordinal'], true)) {
            if (',' === ($message[$offset] ?? null)) {
                ++$offset;
            }
            while ($offset < $length) {
                while ($offset < $length && ctype_space($message[$offset])) {
                    ++$offset;
                }
                if ('}' === ($message[$offset] ?? null)) {
                    ++$offset;
                    break;
                }
                while ($offset < $length && !\in_array($message[$offset], ['{', '}'], true)) {
                    ++$offset;
                }
                if ('{' === ($message[$offset] ?? null)) {
                    ++$offset;
                    array_push($arguments, ...self::icuArguments($message, $offset, true));
                }
            }

            return $arguments;
        }

        array_push($arguments, ...self::icuArguments($message, $offset, true));

        return $arguments;
    }

    private static function skipQuoted(string $message, int &$offset): void
    {
        $length = \strlen($message);
        if ("'" === ($message[$offset] ?? null)) {
            ++$offset;

            return;
        }
        while ($offset < $length) {
            if ("'" !== $message[$offset++]) {
                continue;
            }
            if ("'" === ($message[$offset] ?? null)) {
                ++$offset;
                continue;
            }

            return;
        }
    }
}
