<?php

namespace Symfony\Lsp\Feature\Configuration;

final class ConfigurationNode
{
    /**
     * @param list<ConfigurationNode>          $children
     * @param list<string|int|float|bool|null> $allowedValues
     */
    public function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly bool $required,
        private readonly bool $hasDefault,
        private readonly ?string $defaultSummary,
        private readonly ?string $info,
        private readonly mixed $example,
        private readonly bool $deprecated,
        private readonly array $allowedValues,
        private readonly array $children,
        private readonly ?self $prototype,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function required(): bool
    {
        return $this->required;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultSummary(): ?string
    {
        return $this->defaultSummary;
    }

    public function info(): ?string
    {
        return $this->info;
    }

    public function example(): mixed
    {
        return $this->example;
    }

    public function deprecated(): bool
    {
        return $this->deprecated;
    }

    /** @return list<string|int|float|bool|null> */
    public function allowedValues(): array
    {
        return $this->allowedValues;
    }

    /** @return list<self> */
    public function children(): array
    {
        return $this->children;
    }

    public function prototype(): ?self
    {
        return $this->prototype;
    }

    public function child(string $name): ?self
    {
        if (null !== $child = $this->definedChild($name)) {
            return $child;
        }
        if (null === $this->prototype) {
            return null;
        }

        return $this->prototype->definedChild($name) ?? $this->prototype;
    }

    public function definedChild(string $name): ?self
    {
        foreach ($this->children as $child) {
            if ($child->name() === $name) {
                return $child;
            }
        }

        return null;
    }
}
