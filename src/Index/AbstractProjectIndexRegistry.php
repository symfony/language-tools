<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;

/** @template TIndex of object */
abstract class AbstractProjectIndexRegistry
{
    /** @var array<string, TIndex> */
    private array $indexes = [];

    /** @param class-string<TIndex>|null $indexClass */
    public function __construct(private readonly ?string $indexClass = null)
    {
    }

    /** @return TIndex */
    final public function forProject(Project $project): object
    {
        return $this->indexes[$project->rootPath()] ??= $this->createIndex($project);
    }

    /** @return TIndex */
    protected function createIndex(Project $project): object
    {
        $class = $this->indexClass ?? throw new \LogicException('The project index registry must define an index class or override createIndex().');

        return new $class();
    }
}
