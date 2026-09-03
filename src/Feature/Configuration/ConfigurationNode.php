<?php

namespace Symfony\Lsp\Feature\Configuration;

final class ConfigurationNode
{
    private readonly ?self $entryKeyNode;

    /**
     * @param list<ConfigurationNode>          $children
     * @param list<string|int|float|bool|null> $allowedValues
     * @param list<string>                     $allowedEnumCases
     * @param array<string, bool>              $accepts          normalized value kinds probed on the real tree
     * @param array<string, string>            $aliases
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required,
        public readonly bool $hasDefault,
        public readonly ?string $defaultSummary,
        public readonly ?string $info,
        public readonly mixed $example,
        public readonly bool $deprecated,
        public readonly array $allowedValues,
        public readonly array $allowedEnumCases,
        public readonly array $children,
        public readonly ?self $prototype,
        private readonly array $accepts = [],
        private readonly array $aliases = [],
        private readonly ?string $keyAttribute = null,
        ?string $entryKeyAttribute = null,
        public readonly bool $normalizeKeys = true,
        public readonly bool $allowedValuesTruncated = false,
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
                ?? ([] === $this->prototype->children || $this->prototype->acceptsUnknownKeys() ? $this->prototype : null);
        }
        if ($normalizeName) {
            $name = $this->normalizeChildName($name);
        }
        if ($name === $this->entryKeyNode?->name) {
            return $this->entryKeyNode;
        }
        if (isset($this->aliases[$name])) {
            $name = $this->aliases[$name];
            if (null !== $child = $this->definedChild($name)) {
                return $child->prototype ?? $child;
            }
        }
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
            if ($child->name === $name) {
                return $child;
            }
        }

        return null;
    }
}
