<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigComponent
{
    /**
     * @param list<string>              $properties
     * @param list<TwigComponentAction> $actions
     */
    public function __construct(
        private readonly string $name,
        private readonly string $uri,
        private readonly Range $range,
        private readonly ?string $className = null,
        private readonly ?string $template = null,
        private readonly array $properties = [],
        private readonly bool $live = false,
        private readonly array $actions = [],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function range(): Range
    {
        return $this->range;
    }

    public function className(): ?string
    {
        return $this->className;
    }

    public function template(): ?string
    {
        return $this->template;
    }

    /** @return list<string> */
    public function properties(): array
    {
        return $this->properties;
    }

    public function isLive(): bool
    {
        return $this->live;
    }

    /** @return list<TwigComponentAction> */
    public function actions(): array
    {
        return $this->actions;
    }
}
