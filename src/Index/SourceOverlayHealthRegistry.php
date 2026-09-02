<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectStateInterface;

final class SourceOverlayHealthRegistry implements ProjectStateInterface
{
    /** @var array<string, string> */
    private array $degraded = [];

    public function record(Project $project, string $uri, SourceParseHealth $health): void
    {
        if (SourceParseHealth::Partial === $health) {
            $this->degraded[$uri] = $project->rootPath;

            return;
        }

        $this->clear($uri);
    }

    public function clear(string $uri): void
    {
        unset($this->degraded[$uri]);
    }

    public function isDegraded(string $uri): bool
    {
        return isset($this->degraded[$uri]);
    }

    public function removeProject(Project $project): void
    {
        foreach ($this->degraded as $uri => $rootPath) {
            if ($project->rootPath === $rootPath) {
                unset($this->degraded[$uri]);
            }
        }
    }
}
