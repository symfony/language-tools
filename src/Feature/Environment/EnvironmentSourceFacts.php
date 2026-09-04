<?php

namespace Symfony\Lsp\Feature\Environment;

use Symfony\Lsp\Index\SourceFactsInterface;

final class EnvironmentSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<EnvironmentDeclaration>         $declarations
     * @param list<EnvironmentReference>           $references
     * @param list<MalformedEnvironmentExpression> $malformedExpressions
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $declarations = [],
        public readonly array $references = [],
        public readonly array $malformedExpressions = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->declarations && [] === $this->references && [] === $this->malformedExpressions;
    }
}
