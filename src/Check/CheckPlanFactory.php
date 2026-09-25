<?php

namespace Symfony\Lsp\Check;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\ProjectWorkspace;
use Symfony\Lsp\Project\UriToPathConverter;

final class CheckPlanFactory
{
    public function __construct(
        private readonly ProjectWorkspace $projectWorkspace,
        private readonly CheckFileSelector $fileSelector,
        private readonly CheckProfiler $profiler,
        private readonly UriToPathConverter $uriToPathConverter,
    ) {
    }

    public function create(CheckOptions $options, float $deadline): CheckPlan
    {
        $workspace = $this->workspace($options->workspace);
        $this->profiler->phase('configuration', function () use ($options, $workspace, $deadline): void {
            $this->projectWorkspace->configure(
                [['uri' => $this->uriToPathConverter->toUri($workspace)]],
                $options->overrides,
                $options->projectRoots,
                $options->configurationPath,
            );
            $this->assertBeforeDeadline($deadline, $options->timeout);
        });
        $this->profiler->phase('projectDiscovery', function () use ($options, $deadline): void {
            if ([] === $this->projectWorkspace->discover()) {
                throw new InvalidConfigurationException('No Symfony project was discovered in the workspace.');
            }
            $this->assertBeforeDeadline($deadline, $options->timeout);
        });
        $files = $this->profiler->phase('fileSelection', function () use ($options, $workspace, $deadline) {
            $files = $this->fileSelector->select($workspace, $options->selectors);
            $this->assertBeforeDeadline($deadline, $options->timeout);

            return $files;
        });

        $filesByProject = [];
        $selectedProjects = [];
        foreach ($files as $file) {
            $root = $file->project->rootPath;
            $filesByProject[$root][] = $file;
            $selectedProjects[$root] = $file->project;
        }
        foreach ($selectedProjects as $root => $project) {
            $this->profiler->recordProjectFiles($project, \count($filesByProject[$root]));
        }

        return new CheckPlan($workspace, $files, $filesByProject, $selectedProjects);
    }

    private function workspace(string $workspace): string
    {
        $workspace = Path::canonicalize(Path::isAbsolute($workspace) ? $workspace : Path::join((string) getcwd(), $workspace));
        if (!is_dir($workspace)) {
            throw new InvalidConfigurationException(\sprintf('The workspace "%s" is not a directory.', $workspace));
        }
        if (!is_readable($workspace)) {
            throw new InvalidConfigurationException(\sprintf('The workspace "%s" is unreadable.', $workspace));
        }

        return $workspace;
    }

    private function assertBeforeDeadline(float $deadline, float $timeout): void
    {
        if (microtime(true) >= $deadline) {
            throw new CheckOperationalException(\sprintf('The diagnostics check timed out after %s seconds.', $timeout));
        }
    }
}
