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
            try {
                $data = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }

            return \is_array($data) ? $this->jsonDeclarations($uri, $text, $domain, $locale, $data) : [];
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

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<TranslationDeclaration>
     */
    private function jsonDeclarations(string $uri, string $text, string $domain, string $locale, array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $message) {
            if (!\is_string($key)) {
                continue;
            }
            $fullKey = '' === $prefix ? $key : $prefix.'.'.$key;
            if (\is_array($message)) {
                array_push($result, ...$this->jsonDeclarations($uri, $text, $domain, $locale, $message, $fullKey));
                continue;
            }
            if (!\is_string($message)) {
                continue;
            }
            $offset = strpos($text, '"'.$key.'"');
            $result[] = $this->declaration($fullKey, $message, $domain, $locale, $uri, $text, false === $offset ? 0 : $offset + 1, \strlen($key));
        }

        return $result;
    }

    /** @return list<TranslationDeclaration> */
    private function xliffDeclarations(string $uri, string $text, string $domain, string $locale): array
    {
        preg_match_all('/<(?:trans-unit|unit)\b([^>]*)>(.*?)<\/(?:trans-unit|unit)>/s', $text, $units, \PREG_OFFSET_CAPTURE);
        $result = [];
        foreach ($units[0] as $i => $_) {
            if (!preg_match('/\b(?:resname|name)=[\'\"]([^\'\"]+)[\'\"]/', $units[1][$i][0], $name)) {
                preg_match('/\bid=[\'\"]([^\'\"]+)[\'\"]/', $units[1][$i][0], $name);
            }
            preg_match('/<(?:target|source)(?:\s[^>]*)?>(.*?)<\/(?:target|source)>/s', $units[2][$i][0], $message);
            if (!isset($name[1], $message[1])) {
                continue;
            }
            $key = html_entity_decode(strip_tags($name[1]));
            $value = html_entity_decode(strip_tags($message[1]));
            $offset = strpos($text, $name[1], $units[0][$i][1]);
            $result[] = $this->declaration($key, $value, $domain, $locale, $uri, $text, false === $offset ? 0 : $offset);
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
