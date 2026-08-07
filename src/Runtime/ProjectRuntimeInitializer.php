<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
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

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $plan ??= new RuntimeRefreshPlan();
        $debug = $this->configuration->debug($project);
        if ($plan->preservesContainer() && !$debug) {
            $plan = new RuntimeRefreshPlan(RuntimeRefreshMode::Clear);
        }
        $mode = $plan->mode();
        if (RuntimeRefreshMode::Clear === $mode && !$debug) {
            $result = $this->processRunner->run([
                ...$this->configuration->phpCommand($project),
                $this->configuration->consolePath($project),
                'cache:clear',
                '--env='.$this->configuration->environment($project),
                '--no-interaction',
                '--no-debug',
            ], $project->rootPath(), $cancellation);
            if (0 !== $result->exitCode()) {
                throw new \RuntimeException(\sprintf('The project cache command failed with status %d: %s', $result->exitCode(), trim($result->stderr())));
            }
        }

        $cancellation?->throwIfRequested();
        $sections = $plan->sections() ?? $this->snapshotLoaders->sections();
        $bridge = $this->bridgeInstaller->install($project);
        $result = $this->processRunner->run([
            ...$this->configuration->phpCommand($project),
            $bridge,
            '--project='.$project->rootPath(),
            '--environment='.$this->configuration->environment($project),
            '--debug='.($debug ? '1' : '0'),
            '--sections='.implode(',', $sections),
            ...($plan->preservesContainer() ? ['--targeted-refresh=1'] : []),
        ], $project->rootPath(), $cancellation);

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
        $loadableSnapshot = $snapshot;
        $messages = [];
        foreach (\is_array($errors) ? $errors : [] as $error) {
            if (!\is_array($error)) {
                continue;
            }
            if (\is_string($error['section'] ?? null) && \is_array($loadableSnapshot['sections'] ?? null)) {
                unset($loadableSnapshot['sections'][$error['section']]);
            }
            if (\is_string($error['message'] ?? null)) {
                $messages[] = $error['message'];
            }
        }
        $this->snapshotLoaders->load($project, $loadableSnapshot);

        if (\is_array($errors) && [] !== $errors) {
            throw new \RuntimeException('The project bridge could not load runtime metadata: '.implode('; ', $messages));
        }
    }
}
