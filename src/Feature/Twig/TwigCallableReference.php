<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableReference
{
    public function __construct(
        public readonly TwigCallableKind $kind,
        public readonly string $name,
    ) {
    }
}
