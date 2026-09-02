<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectStateInterface;

final class SourceOverlayHealthRegistry implements ProjectStateInterface
{
    /** @var array<string, array<string, true>> */
    private array $degraded = [];

    public function record(Project $project, string $uri, SourceParseHealth $health): void
    {
        if (SourceParseHealth::Partial === $health) {
            $this->degraded[$project->rootPath][$uri] = true;

            return;
        }

        $this->clear($project, $uri);
    }

    public function clear(Project $project, string $uri): void
    {
        unset($this->degraded[$project->rootPath][$uri]);
        if ([] === ($this->degraded[$project->rootPath] ?? [])) {
            unset($this->degraded[$project->rootPath]);
        }
    }

    public function isDegraded(string $uri): bool
    {
        foreach ($this->degraded as $uris) {
            if (isset($uris[$uri])) {
                return true;
            }
        }

        return false;
    }

    public function removeProject(Project $project): void
    {
        unset($this->degraded[$project->rootPath]);
    }
}
