<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterTree;

final class TwigDocument
{
    public function __construct(private readonly string $source, private readonly TreeSitterTree $tree)
    {
    }

    public function hasErrors(): bool
    {
        return $this->tree->hasError();
    }

    /** @return list<TreeSitterNode> */
    public function nodesOfType(string $type): array
    {
        return $this->tree->nodesOfType($type);
    }

    /** @return list<TreeSitterNode> */
    public function children(TreeSitterNode $node): array
    {
        return $this->tree->children($node);
    }

    /** @return list<TreeSitterNode> */
    public function descendants(TreeSitterNode $node, ?string $type = null): array
    {
        return $this->tree->descendants($node, $type);
    }

    public function directChild(TreeSitterNode $node, string $type): ?TreeSitterNode
    {
        foreach ($this->children($node) as $child) {
            if ($type === $child->type()) {
                return $child;
            }
        }

        return null;
    }

    public function firstDescendant(TreeSitterNode $node, string $type): ?TreeSitterNode
    {
        foreach ($this->descendants($node, $type) as $descendant) {
            return $descendant;
        }

        return null;
    }

    public function directString(TreeSitterNode $node): ?TreeSitterNode
    {
        foreach ($this->children($node) as $child) {
            if (null !== $this->string($child)) {
                return $child;
            }
        }

        return null;
    }

    public function firstString(TreeSitterNode $node): ?TreeSitterNode
    {
        foreach ($this->descendants($node) as $descendant) {
            if (null !== $this->string($descendant)) {
                return $descendant;
            }
        }

        return null;
    }

    public function text(TreeSitterNode $node): string
    {
        return $this->tree->text($node, $this->source);
    }

    /** @return array{string, int, int}|null */
    public function literalString(TreeSitterNode $container): ?array
    {
        $node = $this->firstString($container);
        if (null === $node) {
            return null;
        }
        $text = $this->text($container);
        $start = $container->startByte() + \strlen($text) - \strlen(ltrim($text));
        $end = $container->endByte() - \strlen($text) + \strlen(rtrim($text));
        if ($node->startByte() !== $start || $node->endByte() !== $end) {
            return null;
        }

        return $this->string($node);
    }

    /** @return array{string, int, int}|null */
    public function string(TreeSitterNode $node): ?array
    {
        if (!\in_array($node->type(), ['interpolated_string', 'string'], true)) {
            return null;
        }
        if ('interpolated_string' === $node->type() && [] !== $node->children()) {
            return null;
        }
        $value = $this->text($node);
        if (\strlen($value) < 2 || !\in_array($value[0], ["'", '"'], true) || !str_ends_with($value, $value[0])) {
            return null;
        }

        return [substr($value, 1, -1), $node->startByte() + 1, $node->endByte() - 1];
    }
}
