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
