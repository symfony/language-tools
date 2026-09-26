<?php

namespace Symfony\Lsp\Parser\Php;

/**
 * One element of an array literal: its literal key when it has one, the span
 * of its value and the value itself when it is a complete literal.
 */
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
