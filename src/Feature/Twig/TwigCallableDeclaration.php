<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigCallableDeclaration
{
    public function __construct(
        public readonly TwigCallableKind $kind,
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly ?string $className = null,
        public readonly ?string $method = null,
        public readonly bool $needsEnvironment = false,
        public readonly bool $needsContext = false,
        public readonly bool $variadic = false,
        public readonly bool $optionsKnown = true,
        public readonly bool $needsCharset = false,
        public readonly bool $needsIsSandboxed = false,
    ) {
    }
}
