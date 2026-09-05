<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpLiteral
{
    public function __construct(
        public readonly PhpLiteralKind $kind,
        public readonly string|int|float|bool|null $scalarValue = null,
    ) {
    }
}
