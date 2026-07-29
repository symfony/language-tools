<?php

namespace Symfony\Lsp\Project;

final class ProjectRegistry
{
    /** @var list<Project> */
    private array $projects = [];

    /**
     * @param list<Project> $projects
     */
    public function replace(array $projects): void
    {
        $this->projects = $projects;
    }

    /**
     * @return list<Project>
     */
    public function all(): array
    {
        return $this->projects;
    }
}
