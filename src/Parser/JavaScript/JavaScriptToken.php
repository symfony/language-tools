<?php

namespace Symfony\Lsp\Parser\JavaScript;

final class JavaScriptToken
{
    public function __construct(
        public readonly JavaScriptTokenKind $kind,
        public readonly string $value,
        public readonly int $offset,
        public readonly bool $startsLine,
    ) {
    }

    public function length(): int
    {
        return \strlen($this->value);
    }
}
