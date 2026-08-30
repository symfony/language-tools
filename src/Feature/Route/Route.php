<?php

namespace Symfony\Lsp\Feature\Route;

final class Route
{
    /**
     * @param list<string>          $methods
     * @param list<string>          $schemes
     * @param list<string>          $defaults
     * @param array<string, string> $requirements
     * @param list<string>|null     $parameters
     * @param list<string>|null     $requiredParameters
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $path,
        public readonly array $methods,
        public readonly array $schemes,
        public readonly ?string $host,
        public readonly ?string $controller,
        public readonly array $defaults = [],
        public readonly array $requirements = [],
        public readonly ?string $alias = null,
        public readonly ?string $canonicalName = null,
        private readonly ?array $parameters = null,
        private readonly ?array $requiredParameters = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function parameters(): array
    {
        if (null !== $this->parameters) {
            return $this->parameters;
        }

        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)/', ($this->host ?? '').($this->path ?? ''), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<string>
     */
    public function requiredParameters(): array
    {
        return $this->requiredParameters ?? array_values(array_diff($this->parameters(), $this->defaults));
    }
}
