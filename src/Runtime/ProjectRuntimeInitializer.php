<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

final class ProjectRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly BridgeInstaller $bridgeInstaller,
        private readonly ProcessRunnerInterface $processRunner,
        private readonly RuntimeSnapshotLoaderRegistry $snapshotLoaders,
        private readonly RuntimeConfiguration $configuration,
    ) {
    }

    public function initialize(Project $project): void
    {
        $bridge = $this->bridgeInstaller->install($project);
        $result = $this->processRunner->run([
            ...$this->configuration->phpCommand(),
            $bridge,
            '--project='.$project->rootPath(),
            '--environment='.$this->configuration->environment(),
            '--debug='.($this->configuration->debug() ? '1' : '0'),
            '--sections='.implode(',', $this->snapshotLoaders->sections()),
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

        $errors = $snapshot['errors'] ?? null;
        if (\is_array($errors) && [] !== $errors) {
            $messages = [];
            foreach ($errors as $error) {
                if (\is_array($error) && \is_string($error['message'] ?? null)) {
                    $messages[] = $error['message'];
                }
            }

            throw new \RuntimeException('The project bridge could not load runtime metadata: '.implode('; ', $messages));
        }

        $this->snapshotLoaders->load($project, $snapshot);
    }
}
