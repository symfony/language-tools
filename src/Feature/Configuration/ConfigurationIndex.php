<?php

namespace Symfony\Lsp\Feature\Configuration;

final class ConfigurationIndex
{
    /** @var array<string, ConfigurationNode> */
    private array $roots = [];

    /** @param array<string, ConfigurationNode> $roots */
    public function replace(array $roots): void
    {
        ksort($roots);
        $this->roots = $roots;
    }

    /** @return array<string, ConfigurationNode> */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * Whether a node along the path tolerates keys outside its schema, which
     * makes descendant keys unverifiable.
     *
     * @param list<string> $path
     */
    public function allowsUnknownKeys(array $path): bool
    {
        if ([] === $path) {
            return false;
        }
        $node = $this->roots[array_shift($path)] ?? null;
        while (null !== $node) {
            if ($node->acceptsUnknownKeys()) {
                return true;
            }
            if ([] === $path) {
                return false;
            }
            $node = $node->child(array_shift($path));
        }

        return false;
    }

    /** @param list<string> $path */
    public function find(array $path): ?ConfigurationNode
    {
        if ([] === $path) {
            return null;
        }
        $node = $this->roots[array_shift($path)] ?? null;
        foreach ($path as $name) {
            $node = $node?->child($name);
            if (null === $node) {
                return null;
            }
        }

        return $node;
    }
}
