<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableCallReference
{
    /** @param list<TwigCallableArgumentReference> $arguments */
    public function __construct(
        public readonly TwigCallableKind $kind,
        public readonly string $name,
        public readonly array $arguments,
    ) {
    }
}
