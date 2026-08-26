<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<DependencyInjectionSourceFacts> */
final class DependencyInjectionSourceIndex extends AbstractSourceFactsIndex
{
    private bool $indexed = false;

    /** @var array<string, list<ServiceDeclaration>> */
    private array $serviceDeclarations = [];

    /** @var array<string, list<ParameterDeclaration>> */
    private array $parameterDeclarations = [];

    /** @var array<string, array<string, list<DependencyInjectionReference>>> */
    private array $references = [];

    /** @var array<string, list<PhpClassDeclaration>> */
    private array $classDeclarations = [];

    /** @var array<string, list<ServiceDeclaration>> */
    private array $decorators = [];

    /** @var list<string> */
    private array $serviceIds = [];

    /** @var list<string> */
    private array $parameterNames = [];

    /** @var array<string, bool> */
    private array $subclasses = [];

    /** @return list<ServiceDeclaration> */
    public function serviceDeclarations(string $id): array
    {
        $this->index();

        return $this->serviceDeclarations[$id] ?? [];
    }

    /** @return list<ParameterDeclaration> */
    public function parameterDeclarations(string $name): array
    {
        $this->index();

        return $this->parameterDeclarations[$name] ?? [];
    }

    /** @return list<DependencyInjectionReference> */
    public function references(DependencyInjectionSymbolKind $kind, string $name): array
    {
        $this->index();

        return $this->references[$kind->value][$name] ?? [];
    }

    /** @return list<PhpClassDeclaration> */
    public function classDeclarations(string $className): array
    {
        $this->index();

        return $this->classDeclarations[strtolower(ltrim($className, '\\'))] ?? [];
    }

    public function isSubclassOf(string $className, string $parentClassName): bool
    {
        $className = ltrim($className, '\\');
        $parentClassName = ltrim($parentClassName, '\\');
        $parentKey = strtolower($parentClassName);
        $visited = [];

        while (true) {
            $classKey = strtolower($className);
            $cacheKey = $classKey.'|'.$parentKey;
            if (isset($this->subclasses[$cacheKey])) {
                $result = $this->subclasses[$cacheKey];
                break;
            }
            if (0 === strcasecmp($className, $parentClassName)) {
                $result = true;
                break;
            }
            if (isset($visited[$classKey])) {
                $result = false;
                break;
            }
            $visited[$classKey] = true;
            $declarations = $this->classDeclarations($className);
            if (1 !== \count($declarations) || null === $className = $declarations[0]->parentClassName()) {
                $result = false;
                break;
            }
            $className = ltrim($className, '\\');
        }

        foreach (array_keys($visited) as $classKey) {
            $this->subclasses[$classKey.'|'.$parentKey] = $result;
        }

        return $result;
    }

    /** @return list<string> */
    public function serviceIds(): array
    {
        $this->index();

        return $this->serviceIds;
    }

    /** @return list<string> */
    public function parameterNames(): array
    {
        $this->index();

        return $this->parameterNames;
    }

    /** @return list<ServiceDeclaration> */
    public function decoratorsOf(string $id): array
    {
        $this->index();

        return $this->decorators[$id] ?? [];
    }

    protected function factsChanged(): void
    {
        $this->indexed = false;
        $this->subclasses = [];
    }

    private function index(): void
    {
        if ($this->indexed) {
            return;
        }

        $this->serviceDeclarations = [];
        $this->parameterDeclarations = [];
        $this->references = [];
        $this->classDeclarations = [];
        $this->decorators = [];
        foreach ($this->facts() as $source) {
            foreach ($source->services() as $declaration) {
                $this->serviceDeclarations[$declaration->id()][] = $declaration;
                if (null !== $decorated = $declaration->decorates()) {
                    $this->decorators[$decorated][] = $declaration;
                }
            }
            foreach ($source->parameters() as $declaration) {
                $this->parameterDeclarations[$declaration->name()][] = $declaration;
            }
            foreach ($source->references() as $reference) {
                $this->references[$reference->kind()->value][$reference->name()][] = $reference;
            }
            foreach ($source->classes() as $declaration) {
                $this->classDeclarations[strtolower(ltrim($declaration->className(), '\\'))][] = $declaration;
            }
        }

        $this->serviceIds = array_keys($this->serviceDeclarations);
        sort($this->serviceIds);
        $this->parameterNames = array_keys($this->parameterDeclarations);
        sort($this->parameterNames);
        $this->indexed = true;
    }
}
