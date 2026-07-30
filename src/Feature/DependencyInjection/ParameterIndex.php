<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class ParameterIndex
{
    /** @var array<string, Parameter> */
    private array $parameters = [];

    private bool $complete = false;

    public function replace(bool $complete, Parameter ...$parameters): void
    {
        $this->parameters = [];
        foreach ($parameters as $parameter) {
            $this->parameters[$parameter->name()] = $parameter;
        }
        ksort($this->parameters);
        $this->complete = $complete;
    }

    public function get(string $name): ?Parameter
    {
        return $this->parameters[$name] ?? null;
    }

    /** @return list<Parameter> */
    public function matching(string $prefix): array
    {
        return array_values(array_filter(
            $this->parameters,
            static fn (Parameter $parameter): bool => str_starts_with($parameter->name(), $prefix),
        ));
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
