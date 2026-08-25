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
    public function allowsUnknownKeys(array $path, bool $sequenceItem = false): bool
    {
        if ([] === $path) {
            return false;
        }
        $node = $this->roots[array_shift($path)] ?? null;
        while (null !== $node && [] !== $path) {
            $sequenceChild = $sequenceItem && null !== $node->prototype();
            $child = $node->child(array_shift($path), $sequenceChild);
            if (null === $child) {
                return $node->acceptsUnknownKeys();
            }
            $node = $child;
            $sequenceItem = $sequenceItem && !$sequenceChild;
        }

        return false;
    }

    /**
     * @param list<string> $path
     *
     * @return list<string>
     */
    public function normalizePath(array $path, bool $sequenceItem = false): array
    {
        if ([] === $path) {
            return [];
        }
        $name = ConfigurationNode::normalizeKey(array_shift($path));
        $normalized = [$name];
        $node = $this->roots[$name] ?? null;
        $normalizeKeys = true;
        foreach ($path as $name) {
            $prototype = $node?->prototype();
            $sequenceChild = $sequenceItem && null !== $prototype;
            $normalizingNode = $sequenceChild ? $prototype : $node;
            if (null !== $normalizingNode) {
                $normalizeKeys = $normalizingNode->normalizesKeys();
            }
            $normalized[] = $normalizeKeys ? ConfigurationNode::normalizeKey($name) : $name;
            $node = $node?->child($name, $sequenceChild);
            $sequenceItem = $sequenceItem && !$sequenceChild;
        }

        return $normalized;
    }

    /** @param list<string> $path */
    public function find(array $path, bool $sequenceItem = false): ?ConfigurationNode
    {
        if ([] === $path) {
            return null;
        }
        $node = $this->roots[array_shift($path)] ?? null;
        foreach ($path as $name) {
            $sequenceChild = $sequenceItem && null !== $node?->prototype();
            $node = $node?->child($name, $sequenceChild);
            if (null === $node) {
                return null;
            }
            $sequenceItem = $sequenceItem && !$sequenceChild;
        }

        return $node;
    }
}
