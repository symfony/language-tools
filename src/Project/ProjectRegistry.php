<?php

namespace Symfony\Lsp\Project;

final class ProjectRegistry
{
    /** @var list<Project> */
    private array $projects = [];

    /** @var list<Project> */
    private array $projectsBySpecificity = [];

    /**
     * @param list<Project> $projects
     */
    public function replace(array $projects): ProjectCollectionChange
    {
        $previous = [];
        foreach ($this->projects as $project) {
            $previous[$project->rootPath] = $project;
        }
        $current = [];
        foreach ($projects as $project) {
            $current[$project->rootPath] = $project;
        }
        $this->projects = $projects;
        $this->projectsBySpecificity = $projects;
        usort(
            $this->projectsBySpecificity,
            static fn (Project $left, Project $right): int => \strlen($right->rootUri) <=> \strlen($left->rootUri),
        );

        return new ProjectCollectionChange(
            array_values(array_diff_key($current, $previous)),
            array_values(array_diff_key($previous, $current)),
        );
    }

    public function contains(Project $project): bool
    {
        foreach ($this->projects as $candidate) {
            if ($candidate->rootPath === $project->rootPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Project>
     */
    public function all(): array
    {
        return $this->projects;
    }

    public function forDocumentUri(string $uri): ?Project
    {
        foreach ($this->projectsBySpecificity as $project) {
            $rootUri = rtrim($project->rootUri, '/');
            if ($uri === $rootUri || str_starts_with($uri, $rootUri.'/')) {
                return $project;
            }
        }

        return null;
    }
}
