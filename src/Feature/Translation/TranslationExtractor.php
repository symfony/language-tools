<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;

final class TranslationExtractor
{
    public function __construct(private readonly PositionConverter $converter)
    {
    }

    public function extract(string $uri, string $languageId, string $text): TranslationSourceFacts
    {
        $metadata = $this->resourceMetadata($uri);
        $declarations = null === $metadata ? [] : $this->declarations($uri, $text, ...$metadata);
        $references = \in_array($languageId, ['php', 'twig'], true) ? $this->references($uri, $languageId, $text) : [];

        return new TranslationSourceFacts($uri, $declarations, $references);
    }

    /** @return array{string, string, string}|null */
    private function resourceMetadata(string $uri): ?array
    {
        $path = str_replace('\\', '/', rawurldecode((string) parse_url($uri, \PHP_URL_PATH)));
        if (!str_contains($path, '/translations/')) {
            return null;
        }
        if (!preg_match('/^(.+)\.([A-Za-z][A-Za-z0-9_@-]*)\.(yaml|yml|json|xlf|xliff|php)$/', basename($path), $matches)) {
            return null;
        }

        return [$matches[1], $matches[2], $matches[3]];
    }

    /** @return list<TranslationDeclaration> */
    private function declarations(string $uri, string $text, string $domain, string $locale, string $format): array
    {
        if ('json' === $format) {
            return $this->jsonDeclarations($uri, $text, $domain, $locale);
        }
        if (\in_array($format, ['xlf', 'xliff'], true)) {
            return $this->xliffDeclarations($uri, $text, $domain, $locale);
        }
        if ('php' === $format) {
            preg_match_all('/([\'\"])([^\'\"]+)\1\s*=>\s*([\'\"])(.*?)\3/s', $text, $matches, \PREG_OFFSET_CAPTURE);
            $result = [];
            foreach ($matches[2] as $i => [$key, $offset]) {
                $result[] = $this->declaration($key, $matches[4][$i][0], $domain, $locale, $uri, $text, $offset);
            }

            return $result;
        }

        $stack = [];
        $result = [];
        preg_match_all('/^([ \t]*)([\'\"]?)([^:\'\"]+)\2[ \t]*:[ \t]*(.*)$/m', $text, $lines, \PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as $i => $_) {
            $indent = \strlen($lines[1][$i][0]);
            $key = trim($lines[3][$i][0]);
            $value = trim($lines[4][$i][0]);
            while ([] !== $stack && $stack[array_key_last($stack)][0] >= $indent) {
                array_pop($stack);
            }
            if ('' === $value) {
                $stack[] = [$indent, $key];
                continue;
            }
            $fullKey = implode('.', [...array_column($stack, 1), $key]);
            $message = trim(preg_replace('/\s+#.*$/', '', $value) ?? $value, "'\"");
            $result[] = $this->declaration($fullKey, $message, $domain, $locale, $uri, $text, $lines[3][$i][1], \strlen($key));
        }

        return $result;
    }

    /** @return list<TranslationDeclaration> */
    private function jsonDeclarations(string $uri, string $text, string $domain, string $locale): array
    {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*(?:"|$)|[{}:,]/s', $text, $matches, \PREG_OFFSET_CAPTURE);
        $tokens = $matches[0];
        $index = 0;

        return $this->jsonObject($uri, $text, $domain, $locale, $tokens, $index, []);
    }

    /**
     * @param list<array{string, int}> $tokens
     * @param list<string>             $path
     *
     * @return list<TranslationDeclaration>
     */
    private function jsonObject(string $uri, string $text, string $domain, string $locale, array $tokens, int &$index, array $path): array
    {
        $result = [];
        if ('{' === ($tokens[$index][0] ?? null)) {
            ++$index;
        }
        while (isset($tokens[$index])) {
            [$token, $offset] = $tokens[$index];
            if ('}' === $token) {
                ++$index;
                break;
            }
            if (!str_starts_with($token, '"')) {
                ++$index;
                continue;
            }
            $key = $this->jsonString($token);
            $keyLength = \strlen($token) - (str_ends_with($token, '"') ? 2 : 1);
            ++$index;
            if (':' !== ($tokens[$index][0] ?? null)) {
                continue;
            }
            ++$index;
            $fullPath = [...$path, $key];
            $value = $tokens[$index][0] ?? null;
            if ('{' === $value) {
                array_push($result, ...$this->jsonObject($uri, $text, $domain, $locale, $tokens, $index, $fullPath));
                continue;
            }
            if (\is_string($value) && str_starts_with($value, '"')) {
                $result[] = $this->declaration(
                    implode('.', $fullPath),
                    $this->jsonString($value),
                    $domain,
                    $locale,
                    $uri,
                    $text,
                    $offset + 1,
                    $keyLength,
                );
                ++$index;
            }
        }

        return $result;
    }

    private function jsonString(string $token): string
    {
        $closed = str_ends_with($token, '"') && 1 < \strlen($token);
        $contents = substr($token, 1, $closed ? -1 : null);
        $decoded = json_decode('"'.$contents.'"');

        return \is_string($decoded) ? $decoded : stripcslashes($contents);
    }

    /** @return list<TranslationDeclaration> */
    private function xliffDeclarations(string $uri, string $text, string $domain, string $locale): array
    {
        preg_match_all('/<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?(?:trans-unit|unit)\b([^>]*)>/i', $text, $units, \PREG_OFFSET_CAPTURE);
        $result = [];
        foreach ($units[0] as $i => [$opening, $unitOffset]) {
            $contentOffset = $unitOffset + \strlen($opening);
            $nextOffset = $units[0][$i + 1][1] ?? \strlen($text);
            $content = substr($text, $contentOffset, $nextOffset - $contentOffset);
            preg_match('/\b(?:resname|name)\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i', $units[1][$i][0], $name);
            preg_match('/<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?source(?:\s[^>]*)?>(.*?)(?:<\/(?:[A-Za-z_][A-Za-z0-9_.-]*:)?source>|(?=<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?target\b)|$)/is', $content, $source);
            preg_match('/<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?target(?:\s[^>]*)?>(.*?)(?:<\/(?:[A-Za-z_][A-Za-z0-9_.-]*:)?target>|$)/is', $content, $target);
            if (!isset($name[1]) && isset($source[1])) {
                $name[1] = html_entity_decode(strip_tags($source[1]));
            }
            if (!isset($name[1])) {
                preg_match('/\bid\s*=\s*[\'\"]([^\'\"]+)[\'\"]/i', $units[1][$i][0], $name);
            }
            $message = $target[1] ?? $source[1] ?? null;
            if (!isset($name[1]) || !\is_string($message)) {
                continue;
            }
            $key = html_entity_decode(strip_tags($name[1]));
            $value = html_entity_decode(strip_tags($message));
            $offset = strpos($text, $name[1], $unitOffset);
            $result[] = $this->declaration($key, $value, $domain, $locale, $uri, $text, false === $offset ? $unitOffset : $offset);
        }

        return $result;
    }

    /** @return list<TranslationReference> */
    private function references(string $uri, string $languageId, string $text): array
    {
        $defaultDomain = 'messages';
        if ('twig' === $languageId && preg_match('/{%\s*trans_default_domain\s+([\'\"])([^\'\"]+)\1/', $text, $domainMatch)) {
            $defaultDomain = $domainMatch[2];
        }
        $pattern = 'php' === $languageId
            ? '/(?:->trans|\bt|new\s+TranslatableMessage)\s*\(\s*([\'\"])([^\'\"]+)\1(?:\s*,\s*(\[[^\]]*\]))?(?:\s*,\s*([\'\"])([^\'\"]+)\4)?/s'
            : '/([\'\"])([^\'\"]+)\1\s*\|\s*trans(?:\s*\((\{[^}]*\})?(?:\s*,\s*([\'\"])([^\'\"]+)\4)?)?/s';
        preg_match_all($pattern, $text, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
        $result = [];
        foreach ($matches[2] as $i => [$key, $offset]) {
            if (!\is_string($key)) {
                continue;
            }
            $domain = \is_string($matches[5][$i][0] ?? null) ? $matches[5][$i][0] : $defaultDomain;
            $parameters = \is_string($matches[3][$i][0] ?? null) ? $matches[3][$i][0] : '';
            preg_match_all('/[\'\"](%?[^\'\"]+%?)[\'\"]\s*(?:=>|:)/', $parameters, $placeholderMatches);
            $placeholders = array_map(static fn (string $name): string => trim($name, '%'), $placeholderMatches[1]);
            $result[] = new TranslationReference($key, $domain, $uri, $this->range($text, $offset, \strlen($key)), array_values(array_unique($placeholders)));
        }
        if ('twig' === $languageId) {
            preg_match_all('/\b(?:trans|t)\s*\(\s*([\'\"])([^\'\"]+)\1/', $text, $functions, \PREG_OFFSET_CAPTURE);
            foreach ($functions[2] as [$key, $offset]) {
                $result[] = new TranslationReference($key, $defaultDomain, $uri, $this->range($text, $offset, \strlen($key)));
            }
            preg_match_all('/{%\s*trans(?:\s+from\s+([\'\"])([^\'\"]+)\1)?\s*%}(.+?){%\s*endtrans\s*%}/s', $text, $tags, \PREG_OFFSET_CAPTURE);
            foreach ($tags[3] as $i => [$message, $offset]) {
                $domain = \is_string($tags[2][$i][0] ?? null) ? $tags[2][$i][0] : $defaultDomain;
                $key = trim($message);
                $offset += \strlen($message) - \strlen(ltrim($message));
                $result[] = new TranslationReference($key, $domain, $uri, $this->range($text, $offset, \strlen($key)));
            }
        }

        return $result;
    }

    private function declaration(string $key, string $message, string $domain, string $locale, string $uri, string $text, int $offset, ?int $rangeLength = null): TranslationDeclaration
    {
        return new TranslationDeclaration($key, $domain, $locale, $message, $uri, $this->range($text, $offset, $rangeLength ?? \strlen($key)));
    }

    private function range(string $text, int $offset, int $length): Range
    {
        return new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + $length));
    }
}
