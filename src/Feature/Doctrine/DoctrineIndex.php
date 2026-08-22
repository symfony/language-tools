<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<DoctrineSourceFacts> */
final class DoctrineIndex extends AbstractSourceFactsIndex
{
    /** @var list<DoctrineEntity> */
    private array $runtime = [];

    public function replaceRuntime(DoctrineEntity ...$entities): void
    {
        $this->runtime = array_values($entities);
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
        foreach ($this->runtime as $entity) {
            if ($className === $entity->className()) {
                return $entity;
            }
        }

        return null;
    }

    /** @return list<DoctrineEntity> */
    public function entities(): array
    {
        $entities = [];
        foreach ($this->runtime as $entity) {
            $entities[$entity->className()] = $entity;
        }
        // source facts win: they carry precise declaration ranges
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
}
