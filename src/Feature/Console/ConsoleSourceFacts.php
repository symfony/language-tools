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
        private readonly string $uri,
        private readonly array $declarations,
        private readonly array $references,
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<ConsoleCommandDeclaration> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /** @return list<ConsoleInputReference> */
    public function references(): array
    {
        return $this->references;
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->references;
    }
}
