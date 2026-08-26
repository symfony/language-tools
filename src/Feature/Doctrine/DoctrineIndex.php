<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;

/** @extends AbstractSourceFactsIndex<DoctrineSourceFacts> */
final class DoctrineIndex extends AbstractSourceFactsIndex
{
    /** @var list<DoctrineEntity> */
    private array $runtime = [];
    private bool $indexed = false;

    /** @var array<string, DoctrineEntity> */
    private array $entitiesByClass = [];

    /** @var list<DoctrineEntity> */
    private array $entities = [];

    /** @var array<string, DoctrineEntity> */
    private array $entitiesByRepository = [];

    /** @var array<string, DoctrineRepository> */
    private array $repositoriesByClass = [];

    /** @var array<string, array<string, list<DoctrineSourceSymbol>>> */
    private array $symbols = [];

    public function replaceRuntime(DoctrineEntity ...$entities): void
    {
        $this->runtime = array_values($entities);
        $this->indexed = false;
    }

    public function entity(string $className): ?DoctrineEntity
    {
        $this->index();

        return $this->entitiesByClass[$className] ?? null;
    }

    /** @return list<DoctrineEntity> */
    public function entities(): array
    {
        $this->index();

        return $this->entities;
    }

    public function repository(string $className): ?DoctrineRepository
    {
        $this->index();

        return $this->repositoriesByClass[$className] ?? null;
    }

    public function entityForRepository(string $repositoryClass): ?DoctrineEntity
    {
        $this->index();
        $repository = $this->repositoriesByClass[$repositoryClass] ?? null;

        return null !== $repository ? $this->entitiesByClass[$repository->entityClass()] ?? null : $this->entitiesByRepository[$repositoryClass] ?? null;
    }

    /** @return list<DoctrineSourceSymbol> */
    public function relatedSymbols(DoctrineSourceSymbol $selected): array
    {
        $this->index();
        $symbols = $this->symbols[$selected->kind()->value][$selected->name()] ?? [];
        if (DoctrineSymbolKind::Field !== $selected->kind()) {
            return $symbols;
        }

        $selectedOwner = $this->entityClass($selected->owner());

        return array_values(array_filter($symbols, fn (DoctrineSourceSymbol $symbol): bool => $selectedOwner === $this->entityClass($symbol->owner())));
    }

    protected function factsChanged(): void
    {
        $this->indexed = false;
    }

    private function entityClass(?string $owner): ?string
    {
        if (null === $owner) {
            return null;
        }

        return null !== $this->entity($owner) ? $owner : $this->entityForRepository($owner)?->className();
    }

    private function index(): void
    {
        if ($this->indexed) {
            return;
        }

        $firstRuntimeEntities = [];
        $mergedEntities = [];
        foreach ($this->runtime as $entity) {
            $firstRuntimeEntities[$entity->className()] ??= $entity;
            $mergedEntities[$entity->className()] = $entity;
        }

        $firstSourceEntities = [];
        $this->repositoriesByClass = [];
        $this->symbols = [];
        foreach ($this->facts() as $facts) {
            foreach ($facts->entities() as $entity) {
                $firstSourceEntities[$entity->className()] ??= $entity;
                $mergedEntities[$entity->className()] = $entity;
            }
            foreach ($facts->repositories() as $repository) {
                $this->repositoriesByClass[$repository->className()] ??= $repository;
            }
            foreach ($facts->symbols() as $symbol) {
                $this->symbols[$symbol->kind()->value][$symbol->name()][] = $symbol;
            }
        }

        $this->entitiesByClass = array_replace($firstRuntimeEntities, $firstSourceEntities);
        ksort($mergedEntities);
        $this->entities = array_values($mergedEntities);
        $this->entitiesByRepository = [];
        foreach ($this->entities as $entity) {
            if (null !== $repositoryClass = $entity->repositoryClass()) {
                $this->entitiesByRepository[$repositoryClass] ??= $entity;
            }
        }
        $this->indexed = true;
    }
}
