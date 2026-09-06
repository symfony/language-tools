<?php

namespace Symfony\Lsp\Feature\Route;

use Symfony\Lsp\Feature\DependencyInjection\DependencyInjectionSourceIndex;
use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<RouteSourceFacts> */
final class RouteSourceIndex extends AbstractSourceFactsIndex
{
    private bool $indexed = false;

    /** @var array<string, list<RouteDeclaration>> */
    private array $declarationsByName = [];

    /** @var array<string, list<RouteReferenceLocation>> */
    private array $referencesByUri = [];

    public function __construct(
        private readonly DependencyInjectionSourceIndex $classIndex,
    ) {
        parent::__construct();
    }

    /** @return list<RouteDeclaration> */
    public function declarations(string $name): array
    {
        $this->index();

        return $this->declarationsByName[$name] ?? [];
    }

    /** @return list<RouteReferenceLocation> */
    public function references(string $name): array
    {
        $this->index();
        $references = [];
        foreach ($this->referencesByUri as $sourceReferences) {
            foreach ($sourceReferences as $reference) {
                if ($reference->name === $name && $this->isSupported($reference)) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    /** @return list<RouteReferenceLocation> */
    public function referencesForUri(string $uri): array
    {
        $this->index();

        return array_values(array_filter($this->referencesByUri[$uri] ?? [], $this->isSupported(...)));
    }

    protected function factsChanged(): void
    {
        $this->indexed = false;
    }

    private function index(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->declarationsByName = [];
        $this->referencesByUri = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations as $declaration) {
                $this->declarationsByName[$declaration->name][] = $declaration;
            }
            foreach ($facts->references as $reference) {
                $this->referencesByUri[$reference->uri][] = $reference;
            }
        }
        $this->indexed = true;
    }

    private function isSupported(RouteReferenceLocation $reference): bool
    {
        return null === $reference->controllerClass
            || $this->classIndex->isSubclassOf(
                $reference->controllerClass,
                'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController',
            );
    }
}
