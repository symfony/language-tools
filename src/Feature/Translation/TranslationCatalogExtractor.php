<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Parser\Yaml\YamlMapping;
use Symfony\Lsp\Project\UriToPathConverter;

final class TranslationCatalogExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly YamlDocumentParser $yamlParser,
        private readonly PhpTranslationCatalogParser $phpParser,
    ) {
    }

    /** @return list<TranslationDeclaration> */
    public function extract(string $uri, string $text): array
    {
        $metadata = $this->resourceMetadata($uri);

        return null === $metadata ? [] : $this->declarations($uri, $text, ...$metadata);
    }

    /** @return array{string, string, string}|null */
    private function resourceMetadata(string $uri): ?array
    {
        $path = $this->uriToPathConverter->convert($uri);
        if (null === $path) {
            return null;
        }
        if (preg_match('#/[Tt]ranslations/([A-Za-z][A-Za-z0-9_@-]*)/([^/]+)\.ini$#', '/'.$path, $matches)) {
            return [$matches[2], $matches[1], 'ini'];
        }
        if (!str_contains('/'.$path, '/translations/')) {
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
        if ('ini' === $format) {
            return $this->iniDeclarations($uri, $text, $domain, $locale);
        }
        if ('json' === $format) {
            return $this->jsonDeclarations($uri, $text, $domain, $locale);
        }
        if (\in_array($format, ['xlf', 'xliff'], true)) {
            return $this->xliffDeclarations($uri, $text, $domain, $locale);
        }
        if ('php' === $format) {
            return array_map(
                fn (array $item): TranslationDeclaration => $this->declaration(
                    $item['key'],
                    $item['message'],
                    $domain,
                    $locale,
                    $uri,
                    $text,
                    $item['keyOffset'],
                    $item['keyLength'],
                ),
                $this->phpParser->parse($text),
            );
        }

        $document = $this->yamlParser->parseDocument($text);
        /** @var array<string, YamlMapping> $mappings */
        $mappings = [];
        foreach ($document->mappings as $mapping) {
            if (!$mapping->isSequenceItem()) {
                $mappings[$this->yamlPathKey($mapping->scope, $mapping->path)] = $mapping;
            }
        }

        $result = [];
        foreach ($document->scalars as $scalar) {
            if ($scalar->isSequenceItem() || [] === $scalar->path) {
                continue;
            }
            $scope = null === $scalar->environment ? 'base' : 'when@'.$scalar->environment;
            $mapping = $mappings[$this->yamlPathKey($scope, $scalar->path)] ?? null;
            if (null === $mapping) {
                continue;
            }
            $result[] = $this->declaration(
                implode('.', $scalar->path),
                $scalar->value,
                $domain,
                $locale,
                $uri,
                $text,
                $mapping->keyStartByte,
                $mapping->keyEndByte - $mapping->keyStartByte,
            );
        }

        return $result;
    }

    /** @return list<TranslationDeclaration> */
    private function iniDeclarations(string $uri, string $text, string $domain, string $locale): array
    {
        preg_match_all('/^[^\S\n]*([\w.\-]+)[^\S\n]*=[^\S\n]*(?:"((?:\\\\.|[^"\\\\])*)"|([^\n;]*))/m', $text, $matches, \PREG_OFFSET_CAPTURE);
        $result = [];
        foreach ($matches[1] as $i => [$key, $offset]) {
            [$quoted, $quotedOffset] = $matches[2][$i];
            if (-1 !== $quotedOffset) {
                $result[] = $this->declaration($key, $quoted, $domain, $locale, $uri, $text, $offset);
                continue;
            }
            $message = rtrim($matches[3][$i][0]);
            // PHP's INI parser reads these characters as quotes or operators instead of message text
            if (preg_match('/["\'()|&~!^=]/', $message)) {
                continue;
            }
            $result[] = $this->declaration($key, $message, $domain, $locale, $uri, $text, $offset);
        }

        return $result;
    }

    /** @param list<string> $path */
    private function yamlPathKey(string $scope, array $path): string
    {
        return $scope."\0".json_encode($path, \JSON_THROW_ON_ERROR);
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

    private function declaration(string $key, string $message, string $domain, string $locale, string $uri, string $text, int $offset, ?int $rangeLength = null): TranslationDeclaration
    {
        $icu = str_ends_with($domain, '+intl-icu');

        return new TranslationDeclaration($key, $icu ? substr($domain, 0, -\strlen('+intl-icu')) : $domain, $locale, $message, $uri, $this->converter->toRange($text, $offset, $rangeLength ?? \strlen($key)), $icu);
    }
}
