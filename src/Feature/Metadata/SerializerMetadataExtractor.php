<?php

namespace Symfony\Lsp\Feature\Metadata;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Parser\Php\PhpDocument;

final class SerializerMetadataExtractor
{
    private const GROUP_ATTRIBUTES = [
        'Symfony\\Component\\Serializer\\Attribute\\Groups',
        'Symfony\\Component\\Serializer\\Annotation\\Groups',
    ];

    public function __construct(
        private readonly PositionConverter $converter,
    ) {
    }

    /** @return list<MetadataSourceSymbol> */
    public function symbols(string $uri, string $text, string $source, PhpDocument $php): array
    {
        $symbols = [];
        foreach ($php->attributes as $attribute) {
            if (!\in_array($attribute->name, self::GROUP_ATTRIBUTES, true)) {
                continue;
            }
            foreach ($attribute->arguments as $argument) {
                $expression = $argument->expression;
                $offset = $argument->expressionStartOffset;
                if (\is_string($expression) && \is_int($offset)) {
                    array_push($symbols, ...$this->quotedSymbols($uri, $text, $expression, $offset, true));
                }
            }
        }
        preg_match_all('/["\']groups["\']\s*=>\s*\[(.*?)\]/s', $source, $groupReferences, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        foreach ($groupReferences as $group) {
            array_push($symbols, ...$this->quotedSymbols($uri, $text, $group[1][0], $group[1][1], false));
        }

        return $symbols;
    }

    public function completionContext(string $text, string $source, PhpDocument $php, int $offset): ?MetadataCompletionContext
    {
        $before = substr($source, 0, $offset);
        if (preg_match('/["\']groups["\']\s*=>\s*\[[^\]]*["\']([A-Za-z_][A-Za-z0-9_.:-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)) {
            return $this->context($match[1][0], $text, $match[1][1]);
        }
        if (preg_match('/(?:#\[\s*|,\s*)([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\([^)]*["\']([A-Za-z_][A-Za-z0-9_.:-]*)$/s', $before, $match, \PREG_OFFSET_CAPTURE)
            && \in_array($php->resolveName($match[1][0]), self::GROUP_ATTRIBUTES, true)
        ) {
            return $this->context($match[2][0], $text, $match[2][1]);
        }

        return null;
    }

    /** @return list<MetadataSourceSymbol> */
    private function quotedSymbols(string $uri, string $text, string $fragment, int $base, bool $declaration): array
    {
        preg_match_all('/["\']([A-Za-z_][A-Za-z0-9_.:-]*)["\']/', $fragment, $matches, \PREG_OFFSET_CAPTURE);
        $symbols = [];
        foreach ($matches[1] as [$name, $offset]) {
            $symbols[] = new MetadataSourceSymbol(MetadataSymbolKind::SerializerGroup, $name, $uri, $this->converter->toRange($text, $base + $offset, \strlen($name)), $declaration);
        }

        return $symbols;
    }

    private function context(string $prefix, string $text, int $offset): MetadataCompletionContext
    {
        return new MetadataCompletionContext(MetadataCompletionKind::SerializerGroup, $prefix, $this->converter->toRange($text, $offset, \strlen($prefix)));
    }
}
