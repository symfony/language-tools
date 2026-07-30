<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class DependencyInjectionSourceFacts
{
    /**
     * @param list<ServiceDeclaration>           $services
     * @param list<ParameterDeclaration>         $parameters
     * @param list<DependencyInjectionReference> $references
     * @param list<PhpClassDeclaration>          $classes
     */
    public function __construct(
        private readonly string $uri,
        private readonly array $services = [],
        private readonly array $parameters = [],
        private readonly array $references = [],
        private readonly array $classes = [],
    ) {
    }

    public function uri(): string
    {
        return $this->uri;
    }

    /** @return list<ServiceDeclaration> */
    public function services(): array
    {
        return $this->services;
    }

    /** @return list<ParameterDeclaration> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /** @return list<DependencyInjectionReference> */
    public function references(): array
    {
        return $this->references;
    }

    /** @return list<PhpClassDeclaration> */
    public function classes(): array
    {
        return $this->classes;
    }
}
