<?php

namespace Symfony\Lsp\Feature\Doctrine;

final class DoctrineIndex
{
    /** @var array<string, DoctrineSourceFacts> */
    private array $sources = [];
    /** @var array<string, DoctrineSourceFacts> */
    private array $overlays = [];

    public function replace(DoctrineSourceFacts ...$sources): void
    {
        $this->sources = [];
        foreach ($sources as $source) {
            $this->sources[$source->uri()] = $source;
        }
    }

    public function replaceSource(DoctrineSourceFacts $source): void
    {
        $this->sources[$source->uri()] = $source;
    }

    public function removeSource(string $uri): void
    {
        unset($this->sources[$uri]);
    }

    public function overlay(DoctrineSourceFacts $source): void
    {
        $this->overlays[$source->uri()] = $source;
    }

    public function removeOverlay(string $uri): void
    {
        unset($this->overlays[$uri]);
    }

    public function entity(string $className): ?DoctrineEntity
    {
        foreach ($this->facts() as $facts) {
            foreach ($facts->entities() as $entity) {
                if ($className === $entity->className()) {
                    return $entity;
                }
            }
        }

        return null;
    }

    /** @return list<DoctrineEntity> */
    public function entities(): array
    {
        $entities = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->entities() as $entity) {
                $entities[$entity->className()] = $entity;
            }
        }
        ksort($entities);

        return array_values($entities);
    }

    public function repository(string $className): ?DoctrineRepository
    {
        foreach ($this->facts() as $facts) {
            foreach ($facts->repositories() as $repository) {
                if ($className === $repository->className()) {
                    return $repository;
                }
            }
        }

        return null;
    }

    public function entityForRepository(string $repositoryClass): ?DoctrineEntity
    {
        $repository = $this->repository($repositoryClass);
        if (null !== $repository) {
            return $this->entity($repository->entityClass());
        }
        foreach ($this->entities() as $entity) {
            if ($repositoryClass === $entity->repositoryClass()) {
                return $entity;
            }
        }

        return null;
    }

    /** @return list<DoctrineSourceSymbol> */
    public function relatedSymbols(DoctrineSourceSymbol $selected): array
    {
        $symbols = [];
        $selectedOwner = DoctrineSymbolKind::Field === $selected->kind() ? $this->entityClass($selected->owner()) : null;
        foreach ($this->facts() as $facts) {
            foreach ($facts->symbols() as $symbol) {
                if ($selected->kind() !== $symbol->kind() || $selected->name() !== $symbol->name()) {
                    continue;
                }
                if (DoctrineSymbolKind::Field === $selected->kind() && $selectedOwner !== $this->entityClass($symbol->owner())) {
                    continue;
                }
                $symbols[] = $symbol;
            }
        }

        return $symbols;
    }

    private function entityClass(?string $owner): ?string
    {
        if (null === $owner) {
            return null;
        }

        return null !== $this->entity($owner) ? $owner : $this->entityForRepository($owner)?->className();
    }

    /** @return list<DoctrineSourceFacts> */
    private function facts(): array
    {
        return [...array_values(array_diff_key($this->sources, $this->overlays)), ...array_values($this->overlays)];
    }
}
