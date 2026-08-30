<?php

namespace Symfony\Lsp\Feature\Metadata;

final class ValidationConstraint
{
    /** @param list<string> $options */
    public function __construct(
        public readonly string $name,
        public readonly string $className,
        public readonly array $options,
    ) {
    }
}
