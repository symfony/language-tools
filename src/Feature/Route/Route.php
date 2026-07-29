<?php

namespace Symfony\Lsp\Feature\Route;

final class Route
{
    /**
     * @param list<string> $methods
     * @param list<string> $schemes
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $path,
        private readonly array $methods,
        private readonly array $schemes,
        private readonly ?string $host,
        private readonly ?string $controller,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    /** @return list<string> */
    public function methods(): array
    {
        return $this->methods;
    }

    /** @return list<string> */
    public function schemes(): array
    {
        return $this->schemes;
    }

    public function host(): ?string
    {
        return $this->host;
    }

    public function controller(): ?string
    {
        return $this->controller;
    }
}
