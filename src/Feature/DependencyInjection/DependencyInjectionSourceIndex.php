<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<DependencyInjectionSourceFacts> */
final class DependencyInjectionSourceIndex extends AbstractSourceFactsIndex
{
    /** @var array<string, list<PhpClassDeclaration>>|null */
    private ?array $classDeclarations = null;

    /** @var array<string, bool> */
    private array $subclasses = [];

    /** @return list<ServiceDeclaration> */
    public function serviceDeclarations(string $id): array
    {
        $declarations = [];
        foreach ($this->facts() as $source) {
            foreach ($source->services() as $declaration) {
                if ($declaration->id() === $id) {
                    $declarations[] = $declaration;
                }
            }
        }

        return $declarations;
    }

    /** @return list<ParameterDeclaration> */
    public function parameterDeclarations(string $name): array
    {
        $declarations = [];
        foreach ($this->facts() as $source) {
            foreach ($source->parameters() as $declaration) {
                if ($declaration->name() === $name) {
                    $declarations[] = $declaration;
                }
            }
        }

        return $declarations;
    }

    /** @return list<DependencyInjectionReference> */
    public function references(DependencyInjectionSymbolKind $kind, string $name): array
    {
        $references = [];
        foreach ($this->facts() as $source) {
            foreach ($source->references() as $reference) {
                if ($reference->kind() === $kind && $reference->name() === $name) {
                    $references[] = $reference;
                }
            }
        }

        return $references;
    }

    /** @return list<PhpClassDeclaration> */
    public function classDeclarations(string $className): array
    {
        if (null === $this->classDeclarations) {
            $this->classDeclarations = [];
            foreach ($this->facts() as $source) {
                foreach ($source->classes() as $declaration) {
                    $this->classDeclarations[strtolower(ltrim($declaration->className(), '\\'))][] = $declaration;
                }
            }
        }

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
        $ids = [];
        foreach ($this->facts() as $source) {
            foreach ($source->services() as $declaration) {
                $ids[$declaration->id()] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    public function parameterNames(): array
    {
        $names = [];
        foreach ($this->facts() as $source) {
            foreach ($source->parameters() as $declaration) {
                $names[$declaration->name()] = true;
            }
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /** @return list<ServiceDeclaration> */
    public function decoratorsOf(string $id): array
    {
        $declarations = [];
        foreach ($this->facts() as $source) {
            foreach ($source->services() as $declaration) {
                if ($declaration->decorates() === $id) {
                    $declarations[] = $declaration;
                }
            }
        }

        return $declarations;
    }

    protected function factsChanged(): void
    {
        $this->classDeclarations = null;
        $this->subclasses = [];
    }
}
