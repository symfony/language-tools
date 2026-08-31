<?php

namespace Symfony\Lsp\Parser;

final class SourceComment
{
    public function __construct(
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly int $contentStartOffset,
        public readonly int $contentEndOffset,
        public readonly string $content,
    ) {
    }
}
