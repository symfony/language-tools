<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use Symfony\Lsp\Feature\Configuration\ProjectConfigurationValidationSnapshotLoader;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class ProjectRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly BridgeInstaller $bridgeInstaller,
        private readonly ProcessRunnerInterface $processRunner,
        private readonly RuntimeSnapshotLoaderRegistry $snapshotLoaders,
        private readonly RuntimeConfiguration $configuration,
        private readonly ContainerPathMapper $pathMapper,
        private readonly ProjectRegistry $projects,
        private readonly ProjectConfigurationValidationSnapshotLoader $configurationValidation,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly ?RuntimeSnapshotStore $snapshotStore = null,
        private readonly ?RuntimeSnapshotState $snapshotState = null,
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
        $requestedSections = $plan->sections();
        $sections = $requestedSections ?? $this->snapshotLoaders->sections();
        $bridge = $this->bridgeInstaller->install($project);
        $loadedSections = [];

        try {
            $result = $this->processRunner->run([
                ...$this->configuration->phpCommand($project),
                $this->pathMapper->toContainer($project, $bridge),
                '--project='.$this->pathMapper->toContainer($project, $project->rootPath),
                '--environment='.$this->configuration->environment($project),
                '--debug=1',
                '--sections='.implode(',', $sections),
                '--configuration-generation='.$this->configurationValidation->generation($project),
                ...($plan->preservesContainer() ? ['--targeted-refresh=1'] : []),
                ...(RuntimeRefreshMode::Clear === $mode ? ['--rebuild-container=1'] : []),
            ], $project->rootPath, $cancellation, $this->configuration->bridgeTimeout($project));

            $cancellation?->throwIfRequested();
            if (!$this->projects->contains($project)) {
                // never load metadata for a project removed while the bridge ran
                throw new CancelledException();
            }

            if (0 !== $result->exitCode) {
                throw new BridgeExecutionException(\sprintf('The project bridge failed with status %d.', $result->exitCode));
            }

            $snapshot = $this->decodeSnapshot($result);
            if (1 !== ($snapshot['schemaVersion'] ?? null)) {
                throw new \RuntimeException('The project bridge returned an unsupported snapshot.');
            }
            $timings = $this->bridgeTimings($snapshot['timings'] ?? null, $sections);
            if (null !== $timings) {
                $this->statuses->runtimeTimings($project, $timings);
            }
            $this->configurationValidation->load($project, $snapshot);

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
            $snapshotSections = \is_array($loadableSnapshot['sections'] ?? null) ? $loadableSnapshot['sections'] : [];
            $loadedSections = array_values(array_intersect($sections, array_keys($snapshotSections)));

            if (\is_array($errors) && [] !== $errors) {
                $detail = [] === $failedSections ? '' : ': '.implode(', ', array_keys($failedSections));

                throw new \RuntimeException('The project bridge could not load runtime metadata'.$detail.'.');
            }

            $this->snapshotStore?->save($project, $bridge, $snapshot, $sections, null === $requestedSections);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->restoreSnapshot($project, $bridge, $loadedSections);

            throw $error;
        }
    }

    /** @param list<string> $loadedSections */
    private function restoreSnapshot(Project $project, string $bridge, array $loadedSections): void
    {
        if (!$this->projects->contains($project)
            || null === $this->snapshotStore
            || null === $this->snapshotState
            || $this->snapshotState->has($project)
        ) {
            return;
        }
        $snapshot = $this->snapshotStore->load($project, $bridge);
        if (null === $snapshot) {
            return;
        }

        $restoredSnapshot = $snapshot->snapshot;
        $sections = \is_array($restoredSnapshot['sections'] ?? null) ? $restoredSnapshot['sections'] : [];
        foreach ($loadedSections as $section) {
            unset($sections[$section]);
        }
        if ([] === $sections) {
            return;
        }
        $restoredSnapshot['sections'] = $sections;
        try {
            $this->snapshotLoaders->load($project, $restoredSnapshot);
        } catch (\Throwable) {
            return;
        }
        $this->snapshotState->restore($project, $snapshot->lastSuccessfulAt);
    }

    /**
     * @param list<string> $sections
     *
     * @return array{bootstrapMilliseconds: float, kernelMilliseconds: float, sectionsMilliseconds: array<string, float>, shutdownMilliseconds: float, totalMilliseconds: float}|null
     */
    private function bridgeTimings(mixed $timings, array $sections): ?array
    {
        if (!\is_array($timings) || !\is_array($timings['sectionsMilliseconds'] ?? null)) {
            return null;
        }
        $bootstrap = $this->milliseconds($timings['bootstrapMilliseconds'] ?? null);
        $kernel = $this->milliseconds($timings['kernelMilliseconds'] ?? null);
        $shutdown = $this->milliseconds($timings['shutdownMilliseconds'] ?? null);
        $total = $this->milliseconds($timings['totalMilliseconds'] ?? null);
        if (null === $bootstrap || null === $kernel || null === $shutdown || null === $total) {
            return null;
        }
        $sectionTimings = [];
        foreach ($sections as $section) {
            $milliseconds = $this->milliseconds($timings['sectionsMilliseconds'][$section] ?? null);
            if (null !== $milliseconds) {
                $sectionTimings[$section] = $milliseconds;
            }
        }

        return [
            'bootstrapMilliseconds' => $bootstrap,
            'kernelMilliseconds' => $kernel,
            'sectionsMilliseconds' => $sectionTimings,
            'shutdownMilliseconds' => $shutdown,
            'totalMilliseconds' => $total,
        ];
    }

    private function milliseconds(mixed $value): ?float
    {
        if ((!\is_int($value) && !\is_float($value)) || $value < 0 || !is_finite((float) $value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * The bridge emits its payload as a single trailing JSON line, so stray
     * output around it must not break snapshot decoding.
     *
     * @return array<array-key, mixed>
     */
    private function decodeSnapshot(ProcessResult $result): array
    {
        foreach (array_reverse(preg_split('/\R/', $result->stdout) ?: []) as $line) {
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

        throw new \RuntimeException('The project bridge returned invalid JSON.');
    }
}
