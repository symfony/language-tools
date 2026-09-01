<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;

final class TwigCallArguments
{
    /** @param list<array{name: string|null, value: TreeSitterNode}> $arguments */
    public function __construct(private readonly array $arguments)
    {
    }

    public function get(int $position, string ...$names): ?TreeSitterNode
    {
        $positional = [];
        foreach ($this->arguments as $argument) {
            if (null === $argument['name']) {
                $positional[] = $argument['value'];
            } elseif (\in_array($argument['name'], $names, true)) {
                return $argument['value'];
            }
        }

        return $positional[$position] ?? null;
    }
}
