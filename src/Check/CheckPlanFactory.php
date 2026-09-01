<?php

namespace Symfony\Lsp\Check;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Project\ProjectDiscovery;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectSettings;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class CheckPlanFactory
{
    public function __construct(
        private readonly ProjectConfiguration $projectConfiguration,
        private readonly ProjectDiscovery $projectDiscovery,
        private readonly ProjectRegistry $projects,
        private readonly ProjectSettings $projectSettings,
        private readonly RuntimeConfiguration $runtimeConfiguration,
        private readonly CheckFileSelector $fileSelector,
        private readonly UriToPathConverter $uriToPathConverter,
    ) {
    }

    public function create(CheckOptions $options, float $deadline): CheckPlan
    {
        $workspace = $this->workspace($options->workspace);
        $folder = ['uri' => $this->uriToPathConverter->toUri($workspace)];
        $this->projectConfiguration->load([$folder], $options->configurationPath);
        $this->runtimeConfiguration->configure($options->overrides);
        $this->assertBeforeDeadline($deadline, $options->timeout);

        $projectRoots = [] !== $options->projectRoots
            ? $this->projectRoots($workspace, $options->projectRoots)
            : ($this->projectConfiguration->projectRoots($workspace) ?? []);
        $projects = $this->projectDiscovery->discover([$folder], $projectRoots);
        if ([] !== $options->projectRoots) {
            $this->validateProjectRoots($projectRoots, $projects);
        }
        $this->projectConfiguration->validateProjects($projects);
        $this->projects->replace($projects);
        if ([] === $projects) {
            throw new InvalidConfigurationException('No Symfony project was discovered in the workspace.');
        }
        $this->projectSettings->applyFileSettings($options->overrides);
        $this->assertBeforeDeadline($deadline, $options->timeout);
        $files = $this->fileSelector->select($workspace, $options->selectors);
        $this->assertBeforeDeadline($deadline, $options->timeout);

        $filesByProject = [];
        $selectedProjects = [];
        foreach ($files as $file) {
            $root = $file->project->rootPath;
            $filesByProject[$root][] = $file;
            $selectedProjects[$root] = $file->project;
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

    /**
     * @param list<string>  $roots
     * @param list<Project> $projects
     */
    private function validateProjectRoots(array $roots, array $projects): void
    {
        $discovered = [];
        foreach ($projects as $project) {
            $discovered[Path::canonicalize($project->rootPath)] = true;
        }
        foreach ($roots as $root) {
            if (!isset($discovered[$root])) {
                throw new InvalidConfigurationException(\sprintf('The project root "%s" was not discovered as a Symfony project.', $root));
            }
        }
    }

    /**
     * @param list<string> $roots
     *
     * @return list<string>
     */
    private function projectRoots(string $workspace, array $roots): array
    {
        $resolved = [];
        $realWorkspace = realpath($workspace);
        foreach ($roots as $root) {
            $path = Path::canonicalize(Path::isAbsolute($root) ? $root : Path::join($workspace, $root));
            $realPath = realpath($path);
            if (($workspace !== $path && !Path::isBasePath($workspace, $path))
                || (false !== $realWorkspace
                    && false !== $realPath
                    && Path::canonicalize($realWorkspace) !== Path::canonicalize($realPath)
                    && !Path::isBasePath(Path::canonicalize($realWorkspace), Path::canonicalize($realPath)))
            ) {
                throw new InvalidConfigurationException(\sprintf('The project root "%s" is outside the workspace.', $root));
            }
            $resolved[] = $path;
        }

        return array_values(array_unique($resolved));
    }

    private function assertBeforeDeadline(float $deadline, float $timeout): void
    {
        if (microtime(true) >= $deadline) {
            throw new CheckOperationalException(\sprintf('The diagnostics check timed out after %s seconds.', $timeout));
        }
    }
}
