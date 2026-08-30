<?php

namespace Symfony\Lsp\Feature\Metadata;

final class FormType
{
    /**
     * @param list<string> $options
     * @param list<string> $requiredOptions
     */
    public function __construct(
        public readonly string $className,
        public readonly ?string $blockPrefix,
        public readonly array $options,
        public readonly array $requiredOptions,
    ) {
    }
}
