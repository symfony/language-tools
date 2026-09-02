<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;

final class PhpTranslationCatalogParser
{
    /**
     * @return list<array{key: string, message: string, keyOffset: int, keyLength: int}>
     */
    public function parse(string $source): array
    {
        $tokens = $this->tokens($source);
        $depth = 0;
        foreach ($tokens as $index => $token) {
            if ('{' === $token['text'] || \T_DOLLAR_OPEN_CURLY_BRACES === $token['id']) {
                ++$depth;
                continue;
            }
            if ('}' === $token['text']) {
                $depth = max(0, $depth - 1);
                continue;
            }
            if (0 !== $depth || \T_RETURN !== $token['id']) {
                continue;
            }
            $opening = $this->nextSignificant($tokens, $index + 1);
            if (null === $opening) {
                continue;
            }
            if ('[' === $tokens[$opening]['text']) {
                return $this->arrayEntries($tokens, $opening, ']');
            }
            if (\T_ARRAY === $tokens[$opening]['id']) {
                $opening = $this->nextSignificant($tokens, $opening + 1);
                if (null !== $opening && '(' === $tokens[$opening]['text']) {
                    return $this->arrayEntries($tokens, $opening, ')');
                }
            }
        }

        return [];
    }

    /**
     * @param list<array{id: int|null, text: string, offset: int}> $tokens
     *
     * @return list<array{key: string, message: string, keyOffset: int, keyLength: int}>
     */
    private function arrayEntries(array $tokens, int $opening, string $closing): array
    {
        $entries = [];
        $start = $opening + 1;
        $stack = [$closing];
        for ($index = $start; isset($tokens[$index]); ++$index) {
            $text = $tokens[$index]['text'];
            if (\in_array($text, ['[', '(', '{'], true)) {
                $stack[] = match ($text) {
                    '[' => ']',
                    '(' => ')',
                    '{' => '}',
                };

                continue;
            }
            if ($text === $stack[array_key_last($stack)]) {
                array_pop($stack);
                if ([] === $stack) {
                    if (null !== $entry = $this->entry($tokens, $start, $index)) {
                        $entries[] = $entry;
                    }

                    break;
                }

                continue;
            }
            if (1 === \count($stack) && ',' === $text) {
                if (null !== $entry = $this->entry($tokens, $start, $index)) {
                    $entries[] = $entry;
                }
                $start = $index + 1;
            }
        }

        return $entries;
    }

    /**
     * @param list<array{id: int|null, text: string, offset: int}> $tokens
     *
     * @return array{key: string, message: string, keyOffset: int, keyLength: int}|null
     */
    private function entry(array $tokens, int $start, int $end): ?array
    {
        $keyIndex = $this->nextSignificant($tokens, $start, $end);
        if (null === $keyIndex || \T_CONSTANT_ENCAPSED_STRING !== $tokens[$keyIndex]['id']) {
            return null;
        }
        $arrowIndex = $this->nextSignificant($tokens, $keyIndex + 1, $end);
        if (null === $arrowIndex || \T_DOUBLE_ARROW !== $tokens[$arrowIndex]['id']) {
            return null;
        }
        $valueIndex = $this->nextSignificant($tokens, $arrowIndex + 1, $end);
        if (null === $valueIndex) {
            return null;
        }

        $keyToken = $tokens[$keyIndex];
        $key = $this->quotedString($keyToken['text']);
        if (null === $key) {
            return null;
        }

        return [
            'key' => $key,
            'message' => $this->message($tokens, $valueIndex, $end),
            'keyOffset' => $keyToken['offset'] + 1,
            'keyLength' => max(0, \strlen($keyToken['text']) - 2),
        ];
    }

    /**
     * @param list<array{id: int|null, text: string, offset: int}> $tokens
     */
    private function message(array $tokens, int $start, int $end): string
    {
        $token = $tokens[$start];
        if (\T_CONSTANT_ENCAPSED_STRING === $token['id'] && null === $this->nextSignificant($tokens, $start + 1, $end)) {
            return $this->quotedString($token['text']) ?? '';
        }
        if (\T_START_HEREDOC !== $token['id']) {
            return '';
        }

        $content = '';
        for ($index = $start + 1; $index < $end; ++$index) {
            if (\T_END_HEREDOC === $tokens[$index]['id']) {
                if (null !== $this->nextSignificant($tokens, $index + 1, $end)) {
                    return '';
                }
                $content = $this->stripHeredocIndentation($content, $tokens[$index]['text']);
                $content = preg_replace('/\r?\n$/D', '', $content) ?? $content;

                return str_starts_with($token['text'], "<<<'")
                    ? $content
                    : PhpStringLiteralDecoder::decodeDoubleQuoted($content);
            }
            if (\T_ENCAPSED_AND_WHITESPACE !== $tokens[$index]['id']) {
                return '';
            }
            $content .= $tokens[$index]['text'];
        }

        return '';
    }

    private function quotedString(string $literal): ?string
    {
        $quote = $literal[0] ?? null;
        if (!\in_array($quote, ["'", '"'], true) || !str_ends_with($literal, $quote)) {
            return null;
        }

        return PhpStringLiteralDecoder::decode($quote, substr($literal, 1, -1));
    }

    private function stripHeredocIndentation(string $content, string $end): string
    {
        if (1 !== preg_match('/^([ \t]*)[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $end, $matches) || '' === $matches[1]) {
            return $content;
        }

        return preg_replace('/^'.preg_quote($matches[1], '/').'/m', '', $content) ?? $content;
    }

    /**
     * @return list<array{id: int|null, text: string, offset: int}>
     */
    private function tokens(string $source): array
    {
        $result = [];
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $text = \is_array($token) ? $token[1] : $token;
            $result[] = [
                'id' => \is_array($token) ? $token[0] : null,
                'text' => $text,
                'offset' => $offset,
            ];
            $offset += \strlen($text);
        }

        return $result;
    }

    /**
     * @param list<array{id: int|null, text: string, offset: int}> $tokens
     */
    private function nextSignificant(array $tokens, int $start, ?int $end = null): ?int
    {
        $end ??= \count($tokens);
        for ($index = $start; $index < $end; ++$index) {
            if (!\in_array($tokens[$index]['id'], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
    }
}
