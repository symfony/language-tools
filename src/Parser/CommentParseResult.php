<?php

namespace Symfony\Lsp\Parser;

final class CommentParseResult
{
    /** @param list<SourceComment> $comments */
    public function __construct(
        public readonly string $masked,
        public readonly array $comments,
    ) {
    }
}
