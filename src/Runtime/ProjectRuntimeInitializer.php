<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Feature\Route\RouteIndexRegistry;
use Symfony\Lsp\Feature\Route\RouteSnapshotLoader;
use Symfony\Lsp\Project\Project;

final class ProjectRuntimeInitializer implements RuntimeInitializerInterface
{
    /**
     * @param non-empty-list<string> $phpCommand
     */
    public function __construct(
        private readonly BridgeInstaller $bridgeInstaller,
        private readonly ProcessRunnerInterface $processRunner,
        private readonly RouteIndexRegistry $routeIndexes,
        private readonly array $phpCommand = ['php'],
    ) {
    }

    public function initialize(Project $project): void
    {
        $bridge = $this->bridgeInstaller->install($project);
        $result = $this->processRunner->run([
            ...$this->phpCommand,
            $bridge,
            '--project='.$project->rootPath(),
            '--environment=dev',
            '--debug=1',
            '--sections=routes',
        ], $project->rootPath());

        if (0 !== $result->exitCode()) {
            throw new \RuntimeException(\sprintf('The project bridge failed with status %d: %s', $result->exitCode(), trim($result->stderr())));
        }

        try {
            $snapshot = json_decode($result->stdout(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('The project bridge returned invalid JSON.', 0, $error);
        }

        if (!\is_array($snapshot) || 1 !== ($snapshot['schemaVersion'] ?? null)) {
            throw new \RuntimeException('The project bridge returned an unsupported snapshot.');
        }

        (new RouteSnapshotLoader($this->routeIndexes->forProject($project)))->load($snapshot);
    }
}
