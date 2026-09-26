<?php

namespace Symfony\Lsp\Feature\Doctrine;

use Symfony\Lsp\Index\AbstractSourceFactsIndex;
use Symfony\Lsp\Index\ClassNameKey;
use Symfony\Lsp\Index\SourceSymbolTable;

/** @extends AbstractSourceFactsIndex<DoctrineSourceFacts> */
final class DoctrineIndex extends AbstractSourceFactsIndex
{
    /** @var list<DoctrineEntity> */
    private array $runtime = [];

    /** @var array<string, DoctrineEntity> */
    private array $entitiesByClass = [];

    /** @var list<DoctrineEntity> */
    private array $entities = [];

    /** @var array<string, DoctrineEntity> */
    private array $entitiesByRepository = [];

    /** @var array<string, DoctrineRepository> */
    private array $repositoriesByClass = [];

    /** @var SourceSymbolTable<DoctrineSourceSymbol> */
    private SourceSymbolTable $symbols;

    public function replaceRuntime(DoctrineEntity ...$entities): void
    {
        $this->runtime = array_values($entities);
        $this->invalidate();
    }

    public function entity(string $className): ?DoctrineEntity
    {
        $this->derive();

        return $this->entitiesByClass[ClassNameKey::from($className)] ?? null;
    }

    /** @return list<DoctrineEntity> */
    public function entities(): array
    {
        $this->derive();

        return $this->entities;
    }

    public function repository(string $className): ?DoctrineRepository
    {
        $this->derive();

        return $this->repositoriesByClass[ClassNameKey::from($className)] ?? null;
    }

    public function entityForRepository(string $repositoryClass): ?DoctrineEntity
    {
        $this->derive();
        $key = ClassNameKey::from($repositoryClass);
        $repository = $this->repositoriesByClass[$key] ?? null;

        return null !== $repository ? $this->entitiesByClass[ClassNameKey::from($repository->entityClass)] ?? null : $this->entitiesByRepository[$key] ?? null;
    }

    /** @return list<DoctrineSourceSymbol> */
    public function relatedSymbols(DoctrineSourceSymbol $selected): array
    {
        $this->derive();
        $symbols = $this->symbols->symbols($selected->kind->value, $selected->name);
        if (DoctrineSymbolKind::Field !== $selected->kind) {
            return $symbols;
        }

        $selectedOwner = $this->entityKey($selected->owner);

        return array_values(array_filter($symbols, fn (DoctrineSourceSymbol $symbol): bool => $selectedOwner === $this->entityKey($symbol->owner)));
    }

    protected function build(): void
    {
        $firstRuntimeEntities = [];
        $mergedEntities = [];
        foreach ($this->runtime as $entity) {
            $key = ClassNameKey::from($entity->className);
            $firstRuntimeEntities[$key] ??= $entity;
            $mergedEntities[$key] = $entity;
        }

        $firstSourceEntities = [];
        $this->repositoriesByClass = [];
        $this->symbols = new SourceSymbolTable([
            DoctrineSymbolKind::Entity->value => ClassNameKey::from(...),
            DoctrineSymbolKind::Repository->value => ClassNameKey::from(...),
        ]);
        foreach ($this->facts() as $facts) {
            foreach ($facts->entities as $entity) {
                $key = ClassNameKey::from($entity->className);
                $firstSourceEntities[$key] ??= $entity;
                $mergedEntities[$key] = $entity;
            }
            foreach ($facts->repositories as $repository) {
                $this->repositoriesByClass[ClassNameKey::from($repository->className)] ??= $repository;
            }
            foreach ($facts->symbols as $symbol) {
                $this->symbols->add($symbol->kind->value, $symbol);
            }
        }

        $this->entitiesByClass = array_replace($firstRuntimeEntities, $firstSourceEntities);
        $this->entities = array_values($mergedEntities);
        usort($this->entities, static fn (DoctrineEntity $left, DoctrineEntity $right): int => $left->className <=> $right->className);
        $this->entitiesByRepository = [];
        foreach ($this->entities as $entity) {
            if (null !== $repositoryClass = $entity->repositoryClass) {
                $this->entitiesByRepository[ClassNameKey::from($repositoryClass)] ??= $entity;
            }
        }
    }

    private function entityKey(?string $owner): ?string
    {
        if (null === $owner) {
            return null;
        }
        if (null !== $this->entity($owner)) {
            return ClassNameKey::from($owner);
        }
        $entity = $this->entityForRepository($owner);

        return null === $entity ? null : ClassNameKey::from($entity->className);
    }
}
