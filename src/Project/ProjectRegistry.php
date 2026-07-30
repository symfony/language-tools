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

    public function forDocumentUri(string $uri): ?Project
    {
        $matches = array_filter(
            $this->projects,
            static fn (Project $project): bool => str_starts_with($uri, rtrim($project->rootUri(), '/').'/')
                || $uri === rtrim($project->rootUri(), '/'),
        );

        usort(
            $matches,
            static fn (Project $left, Project $right): int => \strlen($right->rootUri()) <=> \strlen($left->rootUri()),
        );

        return $matches[0] ?? null;
    }
}
