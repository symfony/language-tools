<?php

namespace Symfony\Lsp\Feature\Twig;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;

final class TwigCallableShapeScope
{
    /**
     * @param array<string, TypeNode>              $aliases     Local type aliases by name
     * @param array<string, array{string, string}> $imports     Imported class name and alias by local name
     * @param array<string, array<string, string>> $tokenValues Design token values by token name, by local alias name
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $aliases = [],
        public readonly array $imports = [],
        public readonly array $tokenValues = [],
    ) {
    }
}
