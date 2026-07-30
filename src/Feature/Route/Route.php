<?php

namespace Symfony\Lsp\Feature\Route;

final class Route
{
    /**
     * @param list<string>          $methods
     * @param list<string>          $schemes
     * @param list<string>          $defaults
     * @param array<string, string> $requirements
     */
    public function __construct(
        private readonly string $name,
        private readonly ?string $path,
        private readonly array $methods,
        private readonly array $schemes,
        private readonly ?string $host,
        private readonly ?string $controller,
        private readonly array $defaults = [],
        private readonly array $requirements = [],
        private readonly ?string $alias = null,
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

    /**
     * @return list<string>
     */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /**
     * @return array<string, string>
     */
    public function requirements(): array
    {
        return $this->requirements;
    }

    public function alias(): ?string
    {
        return $this->alias;
    }

    /**
     * @return list<string>
     */
    public function parameters(): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)/', ($this->host ?? '').($this->path ?? ''), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<string>
     */
    public function requiredParameters(): array
    {
        return array_values(array_diff($this->parameters(), $this->defaults));
    }
}
