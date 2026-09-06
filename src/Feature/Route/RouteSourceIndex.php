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

    public function __construct(
        private readonly DependencyInjectionSourceIndex $classIndex,
        private readonly RouteControllerClassifier $controllers,
    ) {
        parent::__construct();
    }

    /** @return list<RouteDeclaration> */
    public function declarations(string $name): array
    {
        $this->indexDeclarations();

        return $this->declarationsByName[$name] ?? [];
    }

    /** @return list<RouteReference> */
    public function references(string $name): array
    {
        $references = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->references as $reference) {
                if ($reference->name === $name && $this->isSupported($reference)) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    /** @return list<RouteReference> */
    public function referencesForUri(string $uri): array
    {
        $facts = $this->factsForUri($uri);

        return null === $facts ? [] : array_values(array_filter($facts->references, $this->isSupported(...)));
    }

    protected function factsChanged(): void
    {
        $this->indexed = false;
    }

    private function indexDeclarations(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->declarationsByName = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->declarations as $declaration) {
                $this->declarationsByName[$declaration->name][] = $declaration;
            }
        }
        $this->indexed = true;
    }

    private function isSupported(RouteReference $reference): bool
    {
        return $this->controllers->isController($reference->controllerClass, null, $this->classIndex);
    }
}
