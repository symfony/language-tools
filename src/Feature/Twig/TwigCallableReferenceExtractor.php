<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigCallableReferenceExtractor
{
    public function __construct(private readonly TwigDocumentParser $parser)
    {
    }

    public function at(string $text, int $offset): ?TwigCallableReference
    {
        $document = $this->parser->parse($text);
        foreach ([
            ['function_call', 'function_identifier', TwigCallableKind::Function],
            ['filter', 'filter_identifier', TwigCallableKind::Filter],
        ] as [$containerType, $identifierType, $kind]) {
            foreach ($document->nodesOfType($containerType) as $container) {
                $identifier = $document->directChild($container, $identifierType);
                if (null === $identifier || !$this->contains($identifier, $offset) || !$this->insideDirective($text, $identifier->startByte())) {
                    continue;
                }
                $name = $document->text($identifier);
                if (1 !== preg_match('/^[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*$/', $name)) {
                    continue;
                }

                return new TwigCallableReference($kind, $name);
            }
        }

        return null;
    }

    private function contains(TreeSitterNode $node, int $offset): bool
    {
        return $offset >= $node->startByte() && $offset <= $node->endByte();
    }

    private function insideDirective(string $text, int $offset): bool
    {
        $before = substr($text, 0, $offset);
        $open = $this->lastOffset($before, '{{', '{%');
        $close = $this->lastOffset($before, '}}', '%}');

        return $open > $close;
    }

    private function lastOffset(string $text, string ...$needles): int
    {
        $offset = -1;
        foreach ($needles as $needle) {
            $position = strrpos($text, $needle);
            if (false !== $position) {
                $offset = max($offset, $position);
            }
        }

        return $offset;
    }
}
