<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableMethodParameter
{
    /** @param list<string> $types */
    public function __construct(
        public readonly string $name,
        public readonly array $types,
        public readonly bool $variadic,
    ) {
    }
}
