<?php

namespace Symfony\Lsp\Feature\Translation;

use Symfony\Lsp\Parser\Php\PhpArgument;
use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpStringLiteral;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigDocument;

final class TranslationParameterAnalyzer
{
    /** @return list<string>|null */
    public function php(PhpDocument $document, ?PhpArgument $argument): ?array
    {
        $array = $document->literalArray($argument);
        if (null === $array || !$array->complete || $array->hasUnknownKeys) {
            return null;
        }

        return $this->normalize(array_map(static fn (PhpStringLiteral $key): string => $key->value, $array->keys));
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
