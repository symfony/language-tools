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
        public readonly string $name,
        public readonly string $uri,
        public readonly Range $range,
        public readonly ?string $className = null,
        public readonly ?string $template = null,
        public readonly array $properties = [],
        public readonly bool $live = false,
        public readonly array $actions = [],
    ) {
    }
}
