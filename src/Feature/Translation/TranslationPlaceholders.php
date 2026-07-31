<?php

namespace Symfony\Lsp\Feature\Translation;

final class TranslationPlaceholders
{
    /** @return list<string> */
    public static function extract(string $message): array
    {
        preg_match_all('/%([^%\s]+)%/', $message, $percentMatches);
        preg_match_all('/\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*(?:,|\})/', $message, $icuMatches);
        $placeholders = array_values(array_unique([
            ...array_filter($percentMatches[1], 'is_string'),
            ...array_filter($icuMatches[1], 'is_string'),
        ]));
        sort($placeholders);

        return $placeholders;
    }
}
