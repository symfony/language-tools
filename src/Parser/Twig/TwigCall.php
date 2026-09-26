<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;

final class TwigCall
{
    /** @var list<TwigArgument>|null */
    private ?array $parsed = null;

    /** @var list<TwigCallArgument|null>|null */
    private ?array $positional = null;

    /** @var list<TwigCallArgument>|null */
    private ?array $named = null;

    public function __construct(
        public readonly string $name,
        public readonly bool $filter,
        public readonly TreeSitterNode $node,
        public readonly TreeSitterNode $identifier,
        private readonly TwigDocument $document,
    ) {
    }

    /**
     * The argument passed by one of the given names, else the one at the
     * given position; the value piped into a filter is its argument 0.
     */
    public function argument(int $position, string ...$names): ?TwigCallArgument
    {
        $this->resolve();
        foreach ($this->named ?? [] as $argument) {
            if (\in_array($argument->name, $names, true)) {
                return $argument;
            }
        }

        return $this->positional[$position] ?? null;
    }

    /** @return list<array{name: string, offset: int}> */
    public function namedArguments(): array
    {
        $this->resolve();
        $named = [];
        foreach ($this->parsed ?? [] as $argument) {
            if (null !== $argument->name && null !== $argument->nameOffset) {
                $named[] = ['name' => $argument->name, 'offset' => $argument->nameOffset];
            }
        }

        return $named;
    }

    private function resolve(): void
    {
        if (null !== $this->parsed) {
            return;
        }
        $this->positional = $this->filter ? [$this->piped()] : [];
        $this->named = [];
        $container = $this->document->directChild($this->node, 'arguments');
        if (null === $container) {
            $this->parsed = [];

            return;
        }

        $text = $this->document->maskedText($container);
        $offset = $container->startByte;
        if (str_starts_with($text, '(')) {
            $text = substr($text, 1);
            ++$offset;
        }
        if (str_ends_with($text, ')')) {
            $text = substr($text, 0, -1);
        }
        $this->parsed = TwigArgumentParser::parse($text, $offset);
        $children = array_filter(
            $this->document->children($container),
            fn (TreeSitterNode $child): bool => !$child->error || 1 !== preg_match('/^[\s\x80-\xff]*$/D', $this->document->maskedText($child)),
        );
        $descendants = $this->document->descendants($container);
        foreach ($this->parsed as $parsed) {
            $segmentEnd = $parsed->offset + \strlen($parsed->text);
            $start = null;
            $end = null;
            foreach ($children as $child) {
                if ($child->startByte >= $parsed->offset && $child->startByte < $segmentEnd) {
                    $start ??= $child->startByte;
                    $end = min(max($end ?? 0, $child->endByte), $segmentEnd);
                }
            }
            $start = null === $parsed->name ? $start : $parsed->valueOffset;
            if (null === $start || null === $end || $end <= $start) {
                continue;
            }
            $node = null;
            foreach ($descendants as $descendant) {
                if ($start === $descendant->startByte && $end === $descendant->endByte) {
                    $node = $descendant;

                    break;
                }
            }
            $argument = new TwigCallArgument($parsed->name, $start, $end, $node, $this->document);
            if (null === $argument->name) {
                $this->positional[] = $argument;
            } else {
                $this->named[] = $argument;
            }
        }
    }

    private function piped(): ?TwigCallArgument
    {
        $parent = $this->document->parent($this->node);
        $previous = null;
        foreach (null === $parent ? [] : $this->document->children($parent) as $sibling) {
            if ($sibling->endByte > $this->node->startByte) {
                break;
            }
            $previous = $sibling;
        }
        if (null === $previous || 'filter' === $previous->type) {
            return null;
        }
        $separator = substr($this->document->maskedSource(), $previous->endByte, $this->node->startByte - $previous->endByte);

        return 1 === preg_match('/^\s*\|\s*$/D', $separator) ? new TwigCallArgument(null, $previous->startByte, $previous->endByte, $previous, $this->document) : null;
    }
}
