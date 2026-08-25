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
     * @param list<int>    $sequenceDepths
     */
    public function allowsUnknownKeys(array $path, array $sequenceDepths = []): bool
    {
        if ([] === $path) {
            return false;
        }
        $node = $this->roots[array_shift($path)] ?? null;
        $depth = 1;
        while (null !== $node && [] !== $path) {
            $child = $node->child(array_shift($path), \in_array($depth, $sequenceDepths, true));
            if (null === $child) {
                return $node->acceptsUnknownKeys();
            }
            $node = $child;
            ++$depth;
        }

        return false;
    }

    /**
     * @param list<string> $path
     * @param list<int>    $sequenceDepths
     *
     * @return list<string>
     */
    public function normalizePath(array $path, array $sequenceDepths = []): array
    {
        if ([] === $path) {
            return [];
        }
        $name = ConfigurationNode::normalizeKey(array_shift($path));
        $normalized = [$name];
        $node = $this->roots[$name] ?? null;
        $normalizeKeys = true;
        $depth = 1;
        foreach ($path as $name) {
            $prototype = $node?->prototype();
            $sequenceChild = \in_array($depth, $sequenceDepths, true) && null !== $prototype;
            $normalizingNode = $sequenceChild ? $prototype : $node;
            if (null !== $normalizingNode) {
                $normalizeKeys = $normalizingNode->normalizesKeys();
            }
            $normalized[] = $normalizeKeys ? ConfigurationNode::normalizeKey($name) : $name;
            $node = $node?->child($name, $sequenceChild);
            ++$depth;
        }

        return $normalized;
    }

    /**
     * @param list<string> $path
     * @param list<int>    $sequenceDepths
     */
    public function find(array $path, array $sequenceDepths = []): ?ConfigurationNode
    {
        if ([] === $path) {
            return null;
        }
        $node = $this->roots[array_shift($path)] ?? null;
        $depth = 1;
        foreach ($path as $name) {
            $node = $node?->child($name, \in_array($depth, $sequenceDepths, true));
            if (null === $node) {
                return null;
            }
            ++$depth;
        }

        return $node;
    }
}
