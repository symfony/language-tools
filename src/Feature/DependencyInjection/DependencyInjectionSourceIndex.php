<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

final class DependencyInjectionSourceIndex
{
    /** @var array<string, DependencyInjectionSourceFacts> */
    private array $sources = [];

    /** @var array<string, DependencyInjectionSourceFacts> */
    private array $overlays = [];

    public function replace(DependencyInjectionSourceFacts ...$sources): void
    {
        $this->sources = [];
        foreach ($sources as $source) {
            $this->sources[$source->uri()] = $source;
        }
    }

    public function replaceSource(DependencyInjectionSourceFacts $source): void
    {
        $this->sources[$source->uri()] = $source;
    }

    public function removeSource(string $uri): void
    {
        unset($this->sources[$uri]);
    }

    public function overlay(DependencyInjectionSourceFacts $source): void
    {
        $this->overlays[$source->uri()] = $source;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    /** @return list<ServiceDeclaration> */
    public function serviceDeclarations(string $id): array
    {
        $declarations = [];
        foreach ($this->sources() as $source) {
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
        foreach ($this->sources() as $source) {
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
        foreach ($this->sources() as $source) {
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
        $declarations = [];
        foreach ($this->sources() as $source) {
            foreach ($source->classes() as $declaration) {
                if (0 === strcasecmp(ltrim($declaration->className(), '\\'), ltrim($className, '\\'))) {
                    $declarations[] = $declaration;
                }
            }
        }

        return $declarations;
    }

    public function isSubclassOf(string $className, string $parentClassName): bool
    {
        $className = ltrim($className, '\\');
        $parentClassName = ltrim($parentClassName, '\\');
        $visited = [];

        while (!isset($visited[strtolower($className)])) {
            if (0 === strcasecmp($className, $parentClassName)) {
                return true;
            }
            $visited[strtolower($className)] = true;
            $declarations = $this->classDeclarations($className);
            if (1 !== \count($declarations) || null === $className = $declarations[0]->parentClassName()) {
                return false;
            }
            $className = ltrim($className, '\\');
        }

        return false;
    }

    /** @return list<string> */
    public function serviceIds(): array
    {
        $ids = [];
        foreach ($this->sources() as $source) {
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
        foreach ($this->sources() as $source) {
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
        foreach ($this->sources() as $source) {
            foreach ($source->services() as $declaration) {
                if ($declaration->decorates() === $id) {
                    $declarations[] = $declaration;
                }
            }
        }

        return $declarations;
    }

    /** @return list<DependencyInjectionSourceFacts> */
    private function sources(): array
    {
        return [
            ...array_values(array_diff_key($this->sources, $this->overlays)),
            ...array_values($this->overlays),
        ];
    }
}
