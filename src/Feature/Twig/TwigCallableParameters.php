<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableParameters
{
    /**
     * @param list<string> $all
     * @param list<string> $nameable
     */
    public function __construct(
        public readonly array $all,
        public readonly array $nameable,
        public readonly bool $variadic,
        public readonly bool $reliable,
    ) {
    }
}
