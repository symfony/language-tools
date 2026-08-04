<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Range;

final class TwigComponentAction
{
    public function __construct(private readonly string $name, private readonly Range $range)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function range(): Range
    {
        return $this->range;
    }
}
