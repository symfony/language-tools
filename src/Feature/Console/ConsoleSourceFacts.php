<?php

namespace Symfony\Lsp\Feature\Console;

use Symfony\Lsp\Index\SourceFactsInterface;

final class ConsoleSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<ConsoleCommandDeclaration> $declarations
     * @param list<ConsoleInputReference>     $references
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $declarations,
        public readonly array $references,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->references;
    }
}
