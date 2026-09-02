<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Parser\Php\PhpMethodDeclaration;

final class TwigCallableResolvedMethod
{
    public function __construct(
        public readonly string $uri,
        public readonly string $source,
        public readonly PhpMethodDeclaration $declaration,
        public readonly bool $reliable,
    ) {
    }
}
