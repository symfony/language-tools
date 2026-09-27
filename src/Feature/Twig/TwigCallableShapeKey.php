<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableShapeKey
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $optional,
    ) {
    }
}
