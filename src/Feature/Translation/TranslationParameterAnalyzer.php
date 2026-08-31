<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpStringLiteralDecoder;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigDocument;

final class TranslationParameterAnalyzer
{
    /** @return list<string>|null */
    public function php(?PhpArgument $argument): ?array
    {
        $expression = trim((string) $argument?->expression);
        if (!str_starts_with($expression, '[') || !str_ends_with($expression, ']')) {
            return null;
        }

        $keys = $this->phpLiteralKeys(substr($expression, 1, -1));

        return null === $keys ? null : $this->normalize($keys);
    }

    /** @return list<string>|null */
    public function twig(TwigDocument $document, ?TreeSitterNode $argument): ?array
    {
        if (null === $argument) {
            return null;
        }
        $expression = trim($document->text($argument));
        if (!str_starts_with($expression, '{') || !str_ends_with($expression, '}')) {
            return null;
        }
        $hash = $document->firstDescendant($argument, 'hash');
        if (null === $hash || $hash->hasError) {
            return null;
        }

        $keys = [];
        $expectsValue = false;
        foreach ($document->children($hash) as $child) {
            if ('hash_key' === $child->type) {
                if ($expectsValue || null === $key = $this->twigHashKey($document, $child)) {
                    return null;
                }
                $keys[] = $key;
                $expectsValue = true;

                continue;
            }
            if ('hash_value' !== $child->type || !$expectsValue) {
                return null;
            }
            $expectsValue = false;
        }

        return $expectsValue ? null : $this->normalize($keys);
    }

    /** @return list<string>|null */
    private function phpLiteralKeys(string $parameters): ?array
    {
        $keys = [];
        $depth = 0;
        $literalKey = null;
        $keyIsLiteral = true;
        $keyParsed = false;
        foreach (token_get_all('<?php '.$parameters) as $token) {
            if (\is_array($token)) {
                if (\T_ELLIPSIS === $token[0] && 0 === $depth) {
                    return null;
                }
                if (\in_array($token[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    if (0 === $depth && !$keyParsed) {
                        $keyIsLiteral = false;
                    }
                    ++$depth;

                    continue;
                }
                if (0 !== $depth || \in_array($token[0], [\T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                    continue;
                }
                if (\T_DOUBLE_ARROW === $token[0] && !$keyParsed) {
                    if (!$keyIsLiteral || null === $literalKey) {
                        return null;
                    }
                    $keys[] = PhpStringLiteralDecoder::decode($literalKey[0], substr($literalKey, 1, -1));
                    $keyParsed = true;
                } elseif (!$keyParsed) {
                    if (\T_CONSTANT_ENCAPSED_STRING === $token[0] && null === $literalKey) {
                        $literalKey = $token[1];
                    } else {
                        $keyIsLiteral = false;
                    }
                }

                continue;
            }
            if (\in_array($token, ['(', '[', '{'], true)) {
                if (0 === $depth && !$keyParsed) {
                    $keyIsLiteral = false;
                }
                ++$depth;
            } elseif (\in_array($token, [')', ']', '}'], true)) {
                --$depth;
            } elseif (0 === $depth && ',' === $token) {
                $literalKey = null;
                $keyIsLiteral = true;
                $keyParsed = false;
            } elseif (0 === $depth && !$keyParsed) {
                $keyIsLiteral = false;
            }
        }

        return $keys;
    }

    private function twigHashKey(TwigDocument $document, TreeSitterNode $key): ?string
    {
        $children = $document->children($key);
        if (1 !== \count($children)) {
            return null;
        }
        $key = $children[0];
        if (null !== $literal = $document->stringLiteral($key)) {
            return $literal->value;
        }

        return 'name' === $key->type ? $document->text($key) : null;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function normalize(array $keys): array
    {
        $keys = array_values(array_unique(array_map(static fn (string $key): string => trim($key, '%'), $keys)));
        sort($keys);

        return $keys;
    }
}
