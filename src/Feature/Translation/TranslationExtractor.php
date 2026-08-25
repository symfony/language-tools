<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Parser\Php\PhpCommentParserInterface;
use Symfony\Lsp\Parser\QuotedArgumentMatcher;
use Symfony\Lsp\Parser\Twig\TwigCommentParser;
use Symfony\Lsp\Parser\Yaml\YamlDocumentParser;
use Symfony\Lsp\Project\UriToPathConverter;

final class TranslationExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly TwigCommentParser $commentParser,
        private readonly YamlDocumentParser $yamlParser,
        private readonly QuotedArgumentMatcher $matcher,
        private readonly PhpCommentParserInterface $phpComments,
    ) {
    }

    public function extract(string $uri, string $languageId, string $text): TranslationSourceFacts
    {
        $metadata = $this->resourceMetadata($uri);
        $declarations = null === $metadata ? [] : $this->declarations($uri, $text, ...$metadata);
        $references = match ($languageId) {
            'twig' => $this->references($uri, $languageId, $this->commentParser->mask($text)),
            'php' => $this->references($uri, $languageId, $this->phpComments->mask($text)),
            default => [],
        };

        return new TranslationSourceFacts($uri, $declarations, $references);
    }

    /** @return array{string, string, string}|null */
    private function resourceMetadata(string $uri): ?array
    {
        $path = $this->uriToPathConverter->convert($uri);
        if (null === $path) {
            return null;
        }
        // Mautic-style catalogs store the locale as a directory: Translations/en_US/messages.ini
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
            preg_match_all('/^\s*([\w.\-]+)\s*=\s*"((?:\\\\.|[^"\\\\])*)"/m', $text, $matches, \PREG_OFFSET_CAPTURE);
            $result = [];
            foreach ($matches[1] as $i => [$key, $offset]) {
                $result[] = $this->declaration($key, $matches[2][$i][0], $domain, $locale, $uri, $text, $offset);
            }

            return $result;
        }
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

        $result = [];
        foreach ($this->yamlParser->parse($text) as $mapping) {
            if ('' === $mapping->value()) {
                continue;
            }
            $result[] = $this->declaration(
                implode('.', $mapping->path()),
                trim($mapping->value(), "'\""),
                $domain,
                $locale,
                $uri,
                $text,
                $mapping->keyStartByte(),
                $mapping->keyEndByte() - $mapping->keyStartByte(),
            );
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
            : '/([\'\"])([^\'\"]+)\1\s*\|\s*trans\b/s';
        preg_match_all($pattern, $text, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);
        $result = [];
        foreach ($matches[2] as $i => [$key, $offset]) {
            if (!\is_string($key)) {
                continue;
            }
            if ('twig' === $languageId) {
                [$parameters, $domain] = $this->twigTransArguments($text, $matches[0][$i], $defaultDomain);
            } else {
                [$parameters, $domain] = $this->phpTransArguments($text, $matches[0][$i], $defaultDomain);
            }
            $placeholders = null === $parameters ? null : $this->parameterKeys($parameters);
            $result[] = new TranslationReference(
                $key,
                $domain,
                $uri,
                $this->range($text, $offset, \strlen($key)),
                null === $parameters || $this->dynamicParameters($text, $matches[0][$i], $parameters) ? null : array_values(array_unique($placeholders ?? [])),
            );
        }
        if ('twig' === $languageId) {
            foreach ($this->matcher->functionCalls($text, ['trans', 't']) as $call) {
                $result[] = new TranslationReference($call->value, $defaultDomain, $uri, $call->range);
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

    /** @return list<string> */
    private function parameterKeys(string $parameters): array
    {
        $parameters = trim($parameters);
        if (\strlen($parameters) >= 2 && \in_array($parameters[0], ['[', '{'], true)) {
            $parameters = substr($parameters, 1, -1);
        }
        $keys = [];
        foreach ($this->splitArguments($parameters) as $argument) {
            if (preg_match('/^\s*([\'\"])(%?[^\'\"]+%?)\1\s*(?:=>|:)/', $argument, $match)) {
                $keys[] = trim($match[2], '%');
            } elseif (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:(?!:)/', $argument, $match)) {
                $keys[] = $match[1];
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * @param array{string|null, int} $match
     *
     * @return array{string|null, string}
     */
    private function phpTransArguments(string $text, array $match, string $defaultDomain): array
    {
        if (!\is_string($match[0])) {
            return [null, $defaultDomain];
        }
        $open = strpos($text, '(', $match[1]);
        if (false === $open) {
            return [null, $defaultDomain];
        }
        $close = $this->matchingDelimiter($text, $open);
        if (null === $close) {
            return [null, $defaultDomain];
        }
        $arguments = $this->splitArguments(substr($text, $open + 1, $close - $open - 1));
        array_shift($arguments);
        $parameters = null;
        $domain = $defaultDomain;
        $position = 1;
        foreach ($arguments as $argument) {
            $name = null;
            $value = $argument;
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(.*)$/s', $argument, $named)) {
                $name = $named[1];
                $value = $named[2];
            } else {
                $name = 1 === $position ? 'parameters' : (2 === $position ? 'domain' : null);
                ++$position;
            }
            $value = trim($value);
            if ('parameters' === $name && str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $parameters = $value;
            } elseif ('domain' === $name && \strlen($value) >= 2 && \in_array($value[0], ["'", '"'], true) && str_ends_with($value, $value[0])) {
                $domain = substr($value, 1, -1);
            }
        }

        return [$parameters, $domain];
    }

    /**
     * @param array{string|null, int} $match
     *
     * @return array{string|null, string}
     */
    private function twigTransArguments(string $text, array $match, string $defaultDomain): array
    {
        if (!\is_string($match[0])) {
            return [null, $defaultDomain];
        }
        $open = $match[1] + \strlen($match[0]);
        while (isset($text[$open]) && ctype_space($text[$open])) {
            ++$open;
        }
        if ('(' !== ($text[$open] ?? null)) {
            return [null, $defaultDomain];
        }
        $close = $this->matchingDelimiter($text, $open);
        if (null === $close) {
            return [null, $defaultDomain];
        }
        $arguments = $this->splitArguments(substr($text, $open + 1, $close - $open - 1));
        $parameters = trim($arguments[0] ?? '');
        if (!str_starts_with($parameters, '{') || !str_ends_with($parameters, '}') || $this->containsUnpack($parameters)) {
            $parameters = null;
        }
        $domain = trim($arguments[1] ?? '');
        if (\strlen($domain) < 2 || !\in_array($domain[0], ["'", '"'], true) || !str_ends_with($domain, $domain[0])) {
            $domain = $defaultDomain;
        } else {
            $domain = substr($domain, 1, -1);
        }

        return [$parameters, $domain];
    }

    private function matchingDelimiter(string $text, int $open): ?int
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $stack = [$text[$open]];
        $quote = null;
        $escaped = false;
        for ($offset = $open + 1, $length = \strlen($text); $offset < $length; ++$offset) {
            $character = $text[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
                continue;
            }
            if (isset($pairs[$character])) {
                $stack[] = $character;
                continue;
            }
            $current = $stack[array_key_last($stack)];
            if ($pairs[$current] !== $character) {
                continue;
            }
            array_pop($stack);
            if ([] === $stack) {
                return $offset;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function splitArguments(string $text): array
    {
        $arguments = [];
        $start = 0;
        $stack = [];
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = \strlen($text); $offset < $length; ++$offset) {
            $character = $text[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif (\in_array($character, ['(', '[', '{'], true)) {
                $stack[] = $character;
            } elseif (\in_array($character, [')', ']', '}'], true) && [] !== $stack) {
                array_pop($stack);
            } elseif (',' === $character && [] === $stack) {
                $arguments[] = substr($text, $start, $offset - $start);
                $start = $offset + 1;
            }
        }
        $arguments[] = substr($text, $start);

        return $arguments;
    }

    /**
     * Parameters passed as expressions, such as trans(params) or
     * trans('key', $params), cannot be verified.
     *
     * @param array{string|null, int} $match
     */
    private function dynamicParameters(string $text, array $match, string $parameters): bool
    {
        if ('' !== $parameters) {
            return $this->containsUnpack($parameters);
        }
        if (!\is_string($match[0])) {
            return false;
        }
        $tail = ltrim(substr($text, $match[1] + \strlen($match[0]), 80));
        if (str_ends_with(rtrim($match[0]), '(')) {
            return '' !== $tail && ')' !== $tail[0];
        }

        return str_contains($match[0], '(') && str_starts_with($tail, ',');
    }

    private function containsUnpack(string $parameters): bool
    {
        $quote = null;
        $escaped = false;
        for ($offset = 0, $length = \strlen($parameters) - 2; $offset < $length; ++$offset) {
            $character = $parameters[$offset];
            if (null !== $quote) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $character) {
                    $escaped = true;
                } elseif ($quote === $character) {
                    $quote = null;
                }
                continue;
            }
            if (\in_array($character, ["'", '"'], true)) {
                $quote = $character;
            } elseif ('...' === substr($parameters, $offset, 3)) {
                return true;
            }
        }

        return false;
    }

    private function declaration(string $key, string $message, string $domain, string $locale, string $uri, string $text, int $offset, ?int $rangeLength = null): TranslationDeclaration
    {
        $icu = str_ends_with($domain, '+intl-icu');

        return new TranslationDeclaration($key, $icu ? substr($domain, 0, -\strlen('+intl-icu')) : $domain, $locale, $message, $uri, $this->range($text, $offset, $rangeLength ?? \strlen($key)), $icu);
    }

    private function range(string $text, int $offset, int $length): Range
    {
        return new Range($this->converter->toPosition($text, $offset), $this->converter->toPosition($text, $offset + $length));
    }
}
