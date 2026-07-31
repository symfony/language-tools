<?php

namespace Symfony\Lsp\Parser\TreeSitter;

final class TreeSitterTree
{
    /**
     * @param list<TreeSitterNode> $nodes
     */
    public function __construct(
        private readonly bool $hasError,
        private readonly array $nodes,
    ) {
    }

    public function hasError(): bool
    {
        return $this->hasError;
    }

    public function root(): TreeSitterNode
    {
        return $this->nodes[0];
    }

    /** @return list<TreeSitterNode> */
    public function children(TreeSitterNode $node): array
    {
        $children = [];
        foreach ($node->children() as $index) {
            $children[] = $this->nodes[$index];
        }

        return $children;
    }

    public function childByField(TreeSitterNode $node, string $field): ?TreeSitterNode
    {
        foreach ($node->children() as $index) {
            $child = $this->nodes[$index];
            if ($field === $child->field()) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<TreeSitterNode> */
    public function descendants(TreeSitterNode $node, ?string $type = null): array
    {
        $descendants = [];
        $pending = $node->children();
        for ($cursor = 0; isset($pending[$cursor]); ++$cursor) {
            $descendant = $this->nodes[$pending[$cursor]];
            if (null === $type || $type === $descendant->type()) {
                $descendants[] = $descendant;
            }
            array_push($pending, ...$descendant->children());
        }

        return $descendants;
    }

    /** @return list<TreeSitterNode> */
    public function nodesOfType(string $type): array
    {
        $matching = [];
        foreach ($this->nodes as $node) {
            if ($type === $node->type()) {
                $matching[] = $node;
            }
        }

        return $matching;
    }

    public function text(TreeSitterNode $node, string $source): string
    {
        return substr($source, $node->startByte(), $node->endByte() - $node->startByte());
    }
}
