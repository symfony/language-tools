<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableRuntimeDeclaration
{
    public function __construct(
        public readonly TwigCallableKind $kind,
        public readonly string $name,
        public readonly string $className,
        public readonly string $method,
        public readonly string $declaringClassName,
        public readonly string $uri,
        public readonly bool $needsCharset = false,
        public readonly bool $needsEnvironment = false,
        public readonly bool $needsContext = false,
        public readonly bool $needsIsSandboxed = false,
        public readonly bool $variadic = false,
    ) {
    }
}
