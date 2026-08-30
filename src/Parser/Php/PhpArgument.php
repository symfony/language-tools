<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpArgument
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?int $nameStartOffset,
        public readonly ?int $nameEndOffset,
        public readonly ?PhpStringLiteral $stringLiteral,
        public readonly ?PhpCallable $callable,
        public readonly ?string $expression,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly ?int $expressionStartOffset,
        public readonly ?int $expressionEndOffset,
    ) {
    }
}
