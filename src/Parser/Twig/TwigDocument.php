<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterTree;

final class TwigDocument
{
    private const CODE_NODE_TYPES = ['output_directive', 'statement_directive', 'comment'];

    private ?string $markup = null;

    public function __construct(
        private readonly string $source,
        private readonly string $masked,
        private readonly TreeSitterTree $tree,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->tree->hasError;
    }

    /**
     * Returns the masked source with every recognized Twig directive blanked,
     * leaving only the bytes a template renders as markup. Unrecoverable
     * regions stay readable so partially typed templates keep their markup.
     */
    public function markup(): string
    {
        if (null !== $this->markup) {
            return $this->markup;
        }
        $markup = $this->masked;
        foreach (self::CODE_NODE_TYPES as $type) {
            foreach ($this->tree->nodesOfType($type) as $node) {
                for ($offset = $node->startByte; $offset < $node->endByte; ++$offset) {
                    $byte = $markup[$offset];
                    if ("\r" !== $byte && "\n" !== $byte && \ord($byte) < 0x80) {
                        $markup[$offset] = ' ';
                    }
                }
            }
        }

        return $this->markup = $markup;
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
            if ($type === $child->type) {
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

    public function stringLiteral(TreeSitterNode $node): ?TwigStringLiteral
    {
        if (!\in_array($node->type, ['interpolated_string', 'string'], true)) {
            return null;
        }
        if ('interpolated_string' === $node->type && [] !== $node->children) {
            return null;
        }
        $value = $this->text($node);
        if (\strlen($value) < 2 || !\in_array($value[0], ["'", '"'], true) || !str_ends_with($value, $value[0])) {
            return null;
        }
        $raw = substr($value, 1, -1);

        return new TwigStringLiteral($raw, TwigStringDecoder::decode($raw, $value[0]), $node->startByte + 1, $node->endByte - 1, $value[0]);
    }

    public function directStringLiteral(TreeSitterNode $node): ?TwigStringLiteral
    {
        foreach ($this->children($node) as $child) {
            if (null !== $literal = $this->stringLiteral($child)) {
                return $literal;
            }
        }

        return null;
    }

    public function firstStringLiteral(TreeSitterNode $node): ?TwigStringLiteral
    {
        foreach ($this->descendants($node) as $descendant) {
            if (null !== $literal = $this->stringLiteral($descendant)) {
                return $literal;
            }
        }

        return null;
    }

    public function soleStringLiteral(TreeSitterNode $container): ?TwigStringLiteral
    {
        $literal = $this->firstStringLiteral($container);
        if (null === $literal) {
            return null;
        }
        $text = $this->text($container);
        $start = $container->startByte + \strlen($text) - \strlen(ltrim($text));
        $end = $container->endByte - \strlen($text) + \strlen(rtrim($text));
        if ($literal->startOffset - 1 !== $start || $literal->endOffset + 1 !== $end) {
            return null;
        }

        return $literal;
    }

    public function text(TreeSitterNode $node): string
    {
        return $this->tree->text($node, $this->source);
    }

    public function maskedText(TreeSitterNode $node): string
    {
        return $this->tree->text($node, $this->masked);
    }
}
