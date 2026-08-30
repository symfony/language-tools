<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Index\SourceFactsInterface;

final class DependencyInjectionSourceFacts implements SourceFactsInterface
{
    /**
     * @param list<ServiceDeclaration>           $services
     * @param list<ParameterDeclaration>         $parameters
     * @param list<DependencyInjectionReference> $references
     * @param list<PhpClassDeclaration>          $classes
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $services = [],
        public readonly array $parameters = [],
        public readonly array $references = [],
        public readonly array $classes = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->services && [] === $this->parameters && [] === $this->references && [] === $this->classes;
    }
}
