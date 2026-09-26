<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\TreeSitter\TreeSitterTree;

final class TwigDocument
{
    private const CODE_NODE_TYPES = ['output_directive', 'statement_directive'];

    private ?string $markup = null;

    /** @var list<TwigCall>|null */
    private ?array $calls = null;

    public function __construct(
        private readonly string $source,
        private readonly string $masked,
        private readonly TreeSitterTree $tree,
        private readonly TwigDirectiveLocator $directiveLocator,
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
                $this->maskRange($markup, $node->startByte, $node->endByte);
            }
        }
        if ($this->tree->hasError) {
            foreach ($this->directiveLocator->recoveryRanges($markup) as $range) {
                $this->maskRange($markup, $range['start'], $range['end']);
            }
        }

        return $this->markup = $markup;
    }

    private function maskRange(string &$masked, int $start, int $end): void
    {
        for ($offset = $start; $offset < $end; ++$offset) {
            $byte = $this->source[$offset];
            if ("\r" !== $byte && "\n" !== $byte && \ord($byte) < 0x80) {
                $masked[$offset] = ' ';
            }
        }
    }

    /**
     * Returns the function and filter calls in source order, restricted to the
     * given names when any are given.
     *
     * @return list<TwigCall>
     */
    public function calls(string ...$names): array
    {
        return $this->callsOfKind(null, $names);
    }

    /** @return list<TwigCall> */
    public function functions(string ...$names): array
    {
        return $this->callsOfKind(false, $names);
    }

    /** @return list<TwigCall> */
    public function filters(string ...$names): array
    {
        return $this->callsOfKind(true, $names);
    }

    /**
     * @param array<string> $names
     *
     * @return list<TwigCall>
     */
    private function callsOfKind(?bool $filter, array $names): array
    {
        if (null === $this->calls) {
            $this->calls = [];
            foreach (['function_call' => 'function_identifier', 'filter' => 'filter_identifier'] as $type => $identifierType) {
                foreach ($this->tree->nodesOfType($type) as $node) {
                    if (null !== $identifier = $this->directChild($node, $identifierType)) {
                        $this->calls[] = new TwigCall($this->text($identifier), 'filter' === $type, $node, $identifier, $this);
                    }
                }
            }
            usort($this->calls, static fn (TwigCall $left, TwigCall $right): int => $left->node->startByte <=> $right->node->startByte);
        }
        if (null === $filter && [] === $names) {
            return $this->calls;
        }

        return array_values(array_filter(
            $this->calls,
            static fn (TwigCall $call): bool => (null === $filter || $filter === $call->filter) && ([] === $names || \in_array($call->name, $names, true)),
        ));
    }

    public function parent(TreeSitterNode $node): ?TreeSitterNode
    {
        return $this->tree->parent($node);
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

    public function maskedSource(): string
    {
        return $this->masked;
    }
}
