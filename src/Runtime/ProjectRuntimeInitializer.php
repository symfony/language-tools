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
        private readonly ContainerPathMapper $pathMapper,
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        if (!$this->configuration->debug($project)) {
            throw new \RuntimeException('Runtime indexing requires Symfony debug mode.');
        }
        $plan ??= new RuntimeRefreshPlan();
        $mode = $plan->mode();
        $cancellation?->throwIfRequested();
        $sections = $plan->sections() ?? $this->snapshotLoaders->sections();
        $bridge = $this->bridgeInstaller->install($project);
        $result = $this->processRunner->run([
            ...$this->configuration->phpCommand($project),
            $this->pathMapper->toContainer($project, $bridge),
            '--project='.$this->pathMapper->toContainer($project, $project->rootPath()),
            '--environment='.$this->configuration->environment($project),
            '--debug=1',
            '--sections='.implode(',', $sections),
            ...($plan->preservesContainer() ? ['--targeted-refresh=1'] : []),
            ...(RuntimeRefreshMode::Clear === $mode ? ['--rebuild-container=1'] : []),
        ], $project->rootPath(), $cancellation);

        if (0 !== $result->exitCode()) {
            throw new \RuntimeException(\sprintf('The project bridge failed with status %d.', $result->exitCode()).$this->failureDetail($result));
        }

        $snapshot = $this->decodeSnapshot($result);
        if (1 !== ($snapshot['schemaVersion'] ?? null)) {
            throw new \RuntimeException('The project bridge returned an unsupported snapshot.');
        }

        $errors = $snapshot['errors'] ?? null;
        $loadableSnapshot = $snapshot;
        $failedSections = [];
        foreach (\is_array($errors) ? $errors : [] as $error) {
            if (!\is_array($error)) {
                continue;
            }
            $section = $error['section'] ?? null;
            if (!\is_string($section)) {
                continue;
            }
            if (\is_array($loadableSnapshot['sections'] ?? null)) {
                unset($loadableSnapshot['sections'][$section]);
            }
            if ('runtime' === $section || \in_array($section, $sections, true)) {
                $failedSections[$section] = true;
            }
        }
        $this->snapshotLoaders->load($project, $loadableSnapshot);

        if (\is_array($errors) && [] !== $errors) {
            $detail = [] === $failedSections ? '' : ': '.implode(', ', array_keys($failedSections));

            throw new \RuntimeException('The project bridge could not load runtime metadata'.$detail.'.');
        }
    }

    /**
     * The bridge emits its payload as a single trailing JSON line, so stray
     * output around it must not break snapshot decoding.
     *
     * @return array<array-key, mixed>
     */
    private function decodeSnapshot(ProcessResult $result): array
    {
        foreach (array_reverse(preg_split('/\R/', $result->stdout()) ?: []) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            try {
                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \RuntimeException('The project bridge returned invalid JSON.'.$this->failureDetail($result));
    }

    private function failureDetail(ProcessResult $result): string
    {
        $stderr = trim($result->stderr());
        if ('' === $stderr) {
            return '';
        }
        if (\strlen($stderr) > 1000) {
            $stderr = substr($stderr, 0, 1000).'...';
        }

        return ' Bridge error output: '.$stderr;
    }
}
