<?php

namespace Symfony\Lsp\Feature\Configuration;

final class ConfigurationNode
{
    private readonly ?self $entryKeyNode;

    /**
     * @param list<ConfigurationNode>          $children
     * @param list<string|int|float|bool|null> $allowedValues
     * @param array<string, bool>              $accepts       normalized value kinds probed on the real tree
     * @param array<string, string>            $aliases
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
        private readonly array $accepts = [],
        private readonly array $aliases = [],
        private readonly ?string $keyAttribute = null,
        ?string $entryKeyAttribute = null,
        private readonly bool $normalizeKeys = true,
    ) {
        $this->entryKeyNode = null === $entryKeyAttribute ? null : new self(
            $entryKeyAttribute,
            'scalar',
            false,
            false,
            null,
            null,
            null,
            false,
            [],
            [],
            null,
        );
    }

    public function acceptsNull(): bool
    {
        return true === ($this->accepts['null'] ?? false);
    }

    public function acceptsTrue(): bool
    {
        return true === ($this->accepts['true'] ?? false);
    }

    public function acceptsFalse(): bool
    {
        return true === ($this->accepts['false'] ?? false);
    }

    public function acceptsScalar(): bool
    {
        return true === ($this->accepts['scalar'] ?? false);
    }

    public function acceptsUnknownKeys(): bool
    {
        return true === ($this->accepts['unknownKeys'] ?? false);
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

    public function normalizesKeys(): bool
    {
        return $this->normalizeKeys;
    }

    public function normalizeChildName(string $name): string
    {
        return $this->normalizeKeys ? self::normalizeKey($name) : $name;
    }

    // Symfony's ArrayNode::preNormalize keeps keys that mix dashes and underscores
    public static function normalizeKey(string $name): string
    {
        return str_contains($name, '-') && !str_contains($name, '_') ? str_replace('-', '_', $name) : $name;
    }

    public function child(string $name, bool $sequenceItem = false, bool $normalizeName = true): ?self
    {
        if ($sequenceItem && null !== $this->prototype) {
            return $this->prototype->child($name, false, $normalizeName)
                ?? ([] === $this->prototype->children() || $this->prototype->acceptsUnknownKeys() ? $this->prototype : null);
        }
        if ($normalizeName) {
            $name = $this->normalizeChildName($name);
        }
        if ($name === $this->entryKeyNode?->name()) {
            return $this->entryKeyNode;
        }
        $name = $this->aliases[$name] ?? $name;
        if (null !== $child = $this->definedChild($name)) {
            return $child;
        }
        if (null === $this->prototype) {
            return null;
        }
        if (null !== $this->keyAttribute) {
            return $this->prototype;
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
