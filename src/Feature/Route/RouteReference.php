<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Document\Range;

final class RouteReference
{
    /**
     * @param list<string>|null $providedParameters
     */
    public function __construct(
        private readonly string $name,
        private readonly Range $range,
        private readonly ?array $providedParameters = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function range(): Range
    {
        return $this->range;
    }

    /**
     * @return list<string>|null
     */
    public function providedParameters(): ?array
    {
        return $this->providedParameters;
    }
}
