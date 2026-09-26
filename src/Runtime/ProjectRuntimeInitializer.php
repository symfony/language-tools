<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use Symfony\Lsp\Feature\Configuration\ProjectConfigurationValidationSnapshotLoader;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Server\ServerLogger;
use Symfony\Lsp\Server\Utf8StringTruncator;

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
        private readonly RuntimeBridgeTimingNormalizer $runtimeBridgeTimings,
        private readonly ServerLogger $logger,
        private readonly Utf8StringTruncator $truncator,
        private readonly ?RuntimeSnapshotStore $snapshotStore = null,
        private readonly ?RuntimeSnapshotState $snapshotState = null,
        private readonly string $releaseMetadataUrl = '',
    ) {
    }

    public function initialize(Project $project, RuntimeRefreshPlan $plan, ?Cancellation $cancellation = null): void
    {
        if (!$this->configuration->debug($project)) {
            throw new \RuntimeException('Runtime indexing requires Symfony debug mode.');
        }
        $cancellation?->throwIfRequested();
        $refreshesEverySection = $plan->refreshesEverySection();
        $sections = $refreshesEverySection ? $this->snapshotLoaders->sections() : $plan->sections();
        $bridge = $this->bridgeInstaller->install($project);
        $kernel = $this->configuration->kernel($project);
        $loadedSections = [];

        try {
            $result = $this->processRunner->run([
                ...$this->configuration->phpCommand($project),
                $this->pathMapper->toContainer($project, $bridge),
                '--project='.$this->pathMapper->toContainer($project, $project->rootPath),
                '--environment='.$this->configuration->environment($project),
                ...(null === $kernel ? [] : ['--kernel='.$kernel]),
                '--debug=1',
                '--sections='.implode(',', $sections),
                '--configuration-generation='.$this->configurationValidation->generation($project),
                // the cause of a failure is reported and logged, never persisted, so it is always collected
                '--error-details=1',
                ...('' === $this->releaseMetadataUrl || !$this->configuration->releaseMetadata($project) ? [] : [
                    '--release-metadata-url='.$this->releaseMetadataUrl,
                    '--release-metadata-cache='.$this->pathMapper->toContainer($project, \dirname($bridge).'/release-metadata.json'),
                ]),
                ...match ($plan->mode()) {
                    RuntimeRefreshMode::Preserve => ['--targeted-refresh=1'],
                    RuntimeRefreshMode::Reuse => [],
                    RuntimeRefreshMode::Rebuild => ['--rebuild-container=1'],
                },
            ], $project->rootPath, $cancellation, $this->configuration->bridgeTimeout($project));

            $cancellation?->throwIfRequested();
            if (!$this->projects->contains($project)) {
                // never load metadata for a project removed while the bridge ran
                throw new CancelledException();
            }

            try {
                $snapshot = $this->decodeSnapshot($result);
                if (1 !== ($snapshot['schemaVersion'] ?? null)) {
                    throw new \RuntimeException('The project bridge returned an unsupported snapshot.');
                }
            } catch (\RuntimeException $error) {
                if (0 !== $result->exitCode) {
                    throw new BridgeExecutionException(\sprintf('The project bridge failed with status %d.', $result->exitCode), previous: $error);
                }

                throw $error;
            }
            if (0 !== $result->exitCode) {
                $this->logger->verbose(\sprintf(
                    'The project bridge exited with status %d after returning runtime metadata.',
                    $result->exitCode,
                ), [$project->rootPath]);
            }
            if (true === ($snapshot['unsupportedSymfonyVersion'] ?? null)) {
                $symfonyBranch = $this->symfonyBranch($snapshot);
                if (null === $symfonyBranch) {
                    throw new \RuntimeException('The project bridge returned an unsupported snapshot.');
                }

                throw new UnsupportedSymfonyVersionException($symfonyBranch);
            }
            $timings = $this->runtimeBridgeTimings->normalize(
                $snapshot['timings'] ?? null,
                $sections,
                $refreshesEverySection ? 'full' : 'targeted',
            );
            if (null !== $timings) {
                $this->statuses->runtimeTimings($project, $timings);
            }
            $this->logSectionWarnings($project, $snapshot);
            $this->configurationValidation->load($project, $snapshot);

            $errors = $snapshot['errors'] ?? null;
            $loadableSnapshot = $snapshot;
            $failedSections = [];
            $sectionErrors = [];
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
                    $chain = $this->runtimeMetadataCauseChain($error['cause'] ?? null);
                    if (null !== $chain && \count($sectionErrors) < 10) {
                        $sectionErrors[] = ['section' => $section, 'chain' => $chain];
                    }
                }
            }
            $this->snapshotLoaders->load($project, $loadableSnapshot);
            $snapshotSections = \is_array($loadableSnapshot['sections'] ?? null) ? $loadableSnapshot['sections'] : [];
            $loadedSections = array_values(array_intersect($sections, array_keys($snapshotSections)));

            if (\is_array($errors) && [] !== $errors) {
                $validation = $snapshot['configurationValidation'] ?? null;
                $applicationBooted = \is_array($validation) && 'valid' === ($validation['status'] ?? null);
                if ([] !== $failedSections
                    && !isset($failedSections['runtime'])
                    && $applicationBooted
                    && [] !== $loadedSections
                ) {
                    $this->snapshotStore?->savePartial($project, $bridge, $loadableSnapshot, $loadedSections);

                    throw new PartialRuntimeMetadataException(array_keys($failedSections), $sectionErrors);
                }

                throw new RuntimeMetadataException(array_keys($failedSections), $sectionErrors);
            }

            $this->snapshotStore?->save($project, $bridge, $snapshot, $sections, $refreshesEverySection);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->restoreSnapshot($project, $bridge, $loadedSections);

            throw $error;
        }
    }

    /**
     * A section reports what it could not describe as a warning, which explains
     * why its metadata is incomplete without failing the refresh.
     *
     * @param array<array-key, mixed> $snapshot
     */
    private function logSectionWarnings(Project $project, array $snapshot): void
    {
        if (!$this->logger->isVerbose()) {
            return;
        }

        $sections = $snapshot['sections'] ?? null;
        foreach (\is_array($sections) ? $sections : [] as $name => $section) {
            if (!\is_string($name) || !\is_array($section)) {
                continue;
            }
            $warnings = $section['warnings'] ?? null;
            foreach (\is_array($warnings) ? $warnings : [] as $warning) {
                if (!\is_string($warning) || '' === $warning) {
                    continue;
                }
                $this->logger->verbose(\sprintf(
                    'The "%s" runtime metadata section reported: %s',
                    $name,
                    $this->truncator->truncate($warning, 500),
                ), [$project->rootPath]);
            }
        }
    }

    /**
     * @return non-empty-list<array{class: string, message: string, origin?: string, frames: list<string>}>|null
     */
    private function runtimeMetadataCauseChain(mixed $cause): ?array
    {
        if (!\is_array($cause) || !\is_array($cause['chain'] ?? null)) {
            return null;
        }

        $chain = [];
        foreach (\array_slice($cause['chain'], 0, 3) as $candidate) {
            if (!\is_array($candidate)
                || !\is_string($candidate['class'] ?? null)
                || '' === $candidate['class']
                || !\is_string($candidate['message'] ?? null)
            ) {
                continue;
            }
            $item = [
                'class' => $this->truncator->truncate($candidate['class'], 300),
                'message' => $this->truncator->truncate($candidate['message'], 500),
                'frames' => [],
            ];
            if (\is_string($candidate['origin'] ?? null) && '' !== $candidate['origin']) {
                $item['origin'] = $this->truncator->truncate($candidate['origin'], 500);
            }
            foreach (\is_array($candidate['frames'] ?? null) ? \array_slice($candidate['frames'], 0, 5) : [] as $frame) {
                if (\is_string($frame) && '' !== $frame) {
                    $item['frames'][] = $this->truncator->truncate($frame, 500);
                }
            }
            $chain[] = $item;
        }

        return [] === $chain ? null : $chain;
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

    /** @param array<array-key, mixed> $snapshot */
    private function symfonyBranch(array $snapshot): ?string
    {
        $project = $snapshot['project'] ?? null;
        $branch = \is_array($project) ? ($project['symfonyBranch'] ?? null) : null;

        return \is_string($branch) && 1 === preg_match('/^[0-9]+\.[0-9]+$/D', $branch) ? $branch : null;
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
