<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableSourceMethod
{
    /** @param list<TwigCallableMethodParameter> $parameters */
    public function __construct(
        public readonly string $className,
        public readonly string $name,
        public readonly array $parameters,
        public readonly bool $reliable,
    ) {
    }
}
