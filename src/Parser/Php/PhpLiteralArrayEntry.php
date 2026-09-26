<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpLiteralArrayEntry
{
    public function __construct(
        public readonly ?PhpStringLiteral $key,
        public readonly int $valueStartOffset,
        public readonly int $valueEndOffset,
        public readonly ?PhpStringLiteral $stringValue,
        public readonly ?PhpLiteral $value,
        public readonly ?PhpClassReference $classReference,
    ) {
    }
}
