<?php

namespace Symfony\Lsp\Feature\Metadata;

final class FormType
{
    /**
     * @param list<string> $options
     * @param list<string> $requiredOptions
     */
    public function __construct(
        private readonly string $className,
        private readonly ?string $blockPrefix,
        private readonly array $options,
        private readonly array $requiredOptions,
    ) {
    }

    public function className(): string
    {
        return $this->className;
    }

    public function blockPrefix(): ?string
    {
        return $this->blockPrefix;
    }

    /** @return list<string> */
    public function options(): array
    {
        return $this->options;
    }

    /** @return list<string> */
    public function requiredOptions(): array
    {
        return $this->requiredOptions;
    }
}
