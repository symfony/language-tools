<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableShapeValue
{
    public function __construct(
        public readonly string $value,
        public readonly ?string $detail = null,
    ) {
    }
}
