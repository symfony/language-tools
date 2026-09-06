<?php

namespace Symfony\Lsp\Parser\Twig;

use Symfony\Lsp\Parser\TreeSitter\TreeSitterNode;

final class TwigCallArguments
{
    /**
     * @param list<array{name: string|null, value: TreeSitterNode}> $arguments
     * @param list<array{name: string, offset: int}>                $named
     */
    public function __construct(
        private readonly array $arguments,
        private readonly array $named = [],
    ) {
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

    /** @return list<array{name: string, offset: int}> */
    public function named(): array
    {
        return $this->named;
    }
}
