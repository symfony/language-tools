<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableCall
{
    /** @param list<TwigCallableArgument> $arguments */
    public function __construct(
        public readonly TwigCallableKind $kind,
        public readonly string $callee,
        public readonly int $calleeOffset,
        public readonly int $argumentsOffset,
        public readonly array $arguments,
        public readonly ?string $prefix = null,
        public readonly bool $hasNestedParentheses = false,
    ) {
    }
}
