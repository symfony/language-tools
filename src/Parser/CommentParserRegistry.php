<?php

namespace Symfony\Lsp\Parser;

final class CommentParserRegistry
{
    /** @param array<string, CommentParserInterface> $parsers */
    public function __construct(private readonly array $parsers)
    {
    }

    public function mask(string $languageId, string $source): string
    {
        return ($this->parsers[$languageId] ?? null)?->mask($source) ?? $source;
    }

    /** @return list<SourceComment> */
    public function comments(string $languageId, string $source): array
    {
        return ($this->parsers[$languageId] ?? null)?->comments($source) ?? [];
    }
}
