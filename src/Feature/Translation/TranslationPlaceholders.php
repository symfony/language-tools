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
        $icuNames = [];
        if ($icu) {
            preg_match_all('/\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*(?:,|\})/', $message, $icuMatches);
            $icuNames = array_filter($icuMatches[1], 'is_string');
        }
        $placeholders = array_values(array_unique([
            ...array_filter($percentMatches[1], 'is_string'),
            ...$icuNames,
        ]));
        sort($placeholders);

        return $placeholders;
    }
}
