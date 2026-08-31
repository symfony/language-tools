<?php

namespace Symfony\Lsp\Check;

final class TokenizedCheckArguments
{
    /** @param list<CheckArgumentToken> $tokens */
    public function __construct(
        public readonly string $format,
        public readonly array $tokens,
    ) {
    }
}
