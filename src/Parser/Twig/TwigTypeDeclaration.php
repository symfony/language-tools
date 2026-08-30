<?php

namespace Symfony\Lsp\Parser\Twig;

final class TwigTypeDeclaration
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $optional,
        public readonly ?string $documentation,
    ) {
    }
}
