<?php

namespace Symfony\Lsp\Index;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Sync\KeyedMutex;
use Symfony\Lsp\Progress\ProgressReporterInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectStateInterface;
use Symfony\Lsp\Server\ServerLogger;

use function Amp\delay;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 * @phpstan-import-type SourceIndexRecord from SourceIndexReaderInterface
 */
final class ApplicationSourceScanner implements ProjectStateInterface
{
    private const LOCK_PREFIX = "source\0";

    /** @var array<string, array<string, SourceIndexMetadata>> */
    private array $entries = [];

    /** @var array<string, DeferredCancellation> */
    private array $activeScans = [];

    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly ProgressReporterInterface $progress,
        private readonly SourceIndexStoreInterface $store,
        private readonly SourceFileEnumerator $files,
        private readonly KeyedMutex $mutex,
        private readonly ServerLogger $logger,
        private readonly SourceIndexProviderPipeline $providers,
        private readonly SourceIndexFileProcessor $processor,
        private readonly SourceIndexOverlayManager $overlays,
    ) {
    }

    public function indexAll(?Cancellation $cancellation = null): void
    {
        foreach ($this->projects->all() as $project) {
            $cancellation?->throwIfRequested();
            try {
                $this->refreshProject($project, $cancellation);
            } catch (CancelledException $error) {
                if (true === $cancellation?->isRequested()) {
                    throw $error;
                }
            }
        }
    }

    public function refreshProject(Project $project, ?Cancellation $cancellation = null): void
    {
        $root = $project->rootPath;
        ($this->activeScans[$root] ?? null)?->cancel();
        $scan = $this->activeScans[$root] = new DeferredCancellation();
        $cancellation = null === $cancellation
            ? $scan->getCancellation()
            : new CompositeCancellation($cancellation, $scan->getCancellation());
        $lock = null;

        try {
            $cancellation->throwIfRequested();
            $lock = $this->mutex->acquire(self::LOCK_PREFIX.$root);
            $cancellation->throwIfRequested();
            $this->refreshProjectUnlocked($project, $cancellation);
        } finally {
            if (($this->activeScans[$root] ?? null) === $scan) {
                unset($this->activeScans[$root]);
            }
            $lock?->release();
        }
    }

    public function removeProject(Project $project): void
    {
        $root = $project->rootPath;
        ($this->activeScans[$root] ?? null)?->cancel();
        unset($this->activeScans[$root], $this->entries[$root]);
    }

    public function indexedHash(Project $project, string $path): ?string
    {
        $relativePath = $this->files->relativePath($project, $path);

        return null === $relativePath ? null : ($this->entries[$project->rootPath][$relativePath]['hash'] ?? null);
    }

    /** @param array<array-key, mixed> $params */
    public function updateOpenDocument(array $params, bool $includeExcluded = false): void
    {
        $uri = $this->uriParameter($params);
        if (null !== $uri) {
            $this->overlays->updateUri($uri, $includeExcluded);
        }
    }

    /** @param array<array-key, mixed> $params */
    public function restoreClosedDocument(array $params): void
    {
        $uri = $this->uriParameter($params);
        if (null !== $uri) {
            $this->overlays->removeUri($uri);
        }
    }

    /** @param array<array-key, mixed> $params */
    public function refreshAfterSave(array $params): SourceFileChange
    {
        $uri = $this->uriParameter($params);

        return null === $uri ? SourceFileChange::untracked() : $this->refreshUri($uri);
    }

    public function refreshUri(string $uri, bool $deleted = false): SourceFileChange
    {
        $location = $this->overlays->locateUri($uri);
        if (null === $location) {
            return SourceFileChange::untracked();
        }

        $lock = $this->mutex->acquire(self::LOCK_PREFIX.$location->project->rootPath);
        try {
            return $this->refreshUriUnlocked($location, $deleted);
        } finally {
            $lock->release();
        }
    }

    private function refreshProjectUnlocked(Project $project, Cancellation $cancellation): void
    {
        $this->statuses->sourceIndexing($project);
        $progress = $this->progress->begin('Symfony source index', $project->rootPath);
        $progressMessage = 'Source index ready';

        try {
            try {
                $entries = $this->scan($project, $this->store->beginRead($project), $cancellation);
            } catch (InvalidSourceIndexEntry) {
                $entries = $this->scan($project, null, $cancellation);
            }
            $this->entries[$project->rootPath] = $entries;
            $this->overlays->reapply($project);
            $this->statuses->sourceReady($project);
        } catch (CancelledException $error) {
            $progressMessage = 'Source indexing canceled';

            throw $error;
        } catch (SourceIndexFileException) {
            $progressMessage = 'Source indexing failed';
            $this->statuses->sourceFailed($project);
        } catch (\Throwable $error) {
            $progressMessage = 'Source indexing failed';
            $this->logger->error($error);
            $this->statuses->sourceFailed($project);
        } finally {
            $this->progress->end($progress, $progressMessage);
        }
    }

    private function refreshUriUnlocked(SourceIndexFileLocation $location, bool $deleted): SourceFileChange
    {
        $project = $location->project;
        // The project can be removed from the workspace while waiting for the lock
        if ($this->projects->forDocumentUri($location->uri)?->rootPath !== $project->rootPath) {
            return SourceFileChange::untracked();
        }

        $projectKey = $project->rootPath;
        $indexed = \array_key_exists($projectKey, $this->entries);
        $entries = $indexed ? $this->entries[$projectKey] : $this->store->loadMetadata($project);
        if ($this->files->isExcluded($project, $location->path) || $this->files->gitignoreExcluded($project->rootPath, $location->path)) {
            if (isset($entries[$location->relativePath])) {
                $this->providers->remove($project, $location->uri);
                unset($entries[$location->relativePath]);
                $this->store->appendDeletion($project, $location->relativePath);
                $this->entries[$projectKey] = $entries;
                $this->statuses->sourceReady($project);
            }

            return SourceFileChange::ignored();
        }
        if ($deleted || !is_file($location->path)) {
            if (!isset($entries[$location->relativePath])) {
                return SourceFileChange::untracked();
            }
            $this->providers->remove($project, $location->uri);
            unset($entries[$location->relativePath]);
            $this->store->appendDeletion($project, $location->relativePath);
            $change = SourceFileChange::factsChanged([]);
        } elseif (null === $languageId = $this->files->languageId($location->path)) {
            return SourceFileChange::untracked();
        } else {
            $updated = $this->processor->update(
                $location,
                $languageId,
                $entries[$location->relativePath] ?? null,
                $indexed,
            );
            if (null === $updated) {
                return SourceFileChange::untracked();
            }
            if (null === $updated->metadata || null === $updated->payloads) {
                return $updated->change;
            }
            $entries[$location->relativePath] = $updated->metadata;
            $this->store->append($project, $location->relativePath, $updated->metadata, $updated->payloads);
            $change = $updated->change;
        }

        $this->entries[$projectKey] = $entries;
        $this->statuses->sourceReady($project);

        return $change;
    }

    /** @return array<string, SourceIndexMetadata> */
    private function scan(Project $project, ?SourceIndexReaderInterface $reader, Cancellation $cancellation): array
    {
        $writer = null;
        try {
            $this->providers->begin($project);
            $writer = $this->store->beginRewrite($project);
            // Automatic collection repeatedly walks the growing cyclic facts graph.
            $gcWasEnabled = gc_enabled();
            if ($gcWasEnabled) {
                gc_disable();
            }

            try {
                $entries = $this->scanSourceFiles($project, $reader, $writer, $cancellation, $gcWasEnabled);
            } finally {
                if ($gcWasEnabled) {
                    gc_collect_cycles();
                    gc_enable();
                }
            }

            $reader?->close();
            $reader = null;
            $this->providers->finish($project);
            $writer->commit();

            return $entries;
        } catch (\Throwable $error) {
            $writer?->abort();

            throw $error;
        } finally {
            $reader?->close();
        }
    }

    /** @return array<string, SourceIndexMetadata> */
    private function scanSourceFiles(Project $project, ?SourceIndexReaderInterface $reader, SourceIndexWriterInterface $writer, Cancellation $cancellation, bool $collectCycles): array
    {
        $entries = [];
        $parsedCount = 0;
        if (null === $reader || !$reader->hasRecords()) {
            foreach ($this->sourceFiles($project, $cancellation) as $relativePath => $source) {
                $processed = $this->scanSourceFile($source['location'], $source['languageId'], null);
                if (null === $processed) {
                    continue;
                }
                $entries[$relativePath] = $processed->metadata;
                $writer->add($relativePath, $processed->metadata, $processed->payloads);
                if ($processed->parsed && $collectCycles && 0 === ++$parsedCount % 256) {
                    gc_collect_cycles();
                }
            }

            return $entries;
        }

        $sources = iterator_to_array($this->sourceFiles($project, $cancellation));
        $processedCount = 0;
        foreach ($reader->records() as $relativePath => $cached) {
            if (0 === ++$processedCount % 64) {
                delay(0, cancellation: $cancellation);
            }
            $cancellation->throwIfRequested();
            $source = $sources[$relativePath] ?? null;
            if (null === $source) {
                continue;
            }
            unset($sources[$relativePath]);
            $processed = $this->scanSourceFile($source['location'], $source['languageId'], $cached);
            if (null === $processed) {
                continue;
            }
            $entries[$relativePath] = $processed->metadata;
            $writer->add($relativePath, $processed->metadata, $processed->payloads);
            if ($processed->parsed && $collectCycles && 0 === ++$parsedCount % 256) {
                gc_collect_cycles();
            }
        }
        foreach ($sources as $relativePath => $source) {
            if (0 === ++$processedCount % 64) {
                delay(0, cancellation: $cancellation);
            }
            $cancellation->throwIfRequested();
            $processed = $this->scanSourceFile($source['location'], $source['languageId'], null);
            if (null === $processed) {
                continue;
            }
            $entries[$relativePath] = $processed->metadata;
            $writer->add($relativePath, $processed->metadata, $processed->payloads);
            if ($processed->parsed && $collectCycles && 0 === ++$parsedCount % 256) {
                gc_collect_cycles();
            }
        }

        return $entries;
    }

    /** @param ?SourceIndexRecord $cached */
    private function scanSourceFile(SourceIndexFileLocation $location, string $languageId, ?array $cached): ?ProcessedSourceIndexFile
    {
        try {
            return $this->processor->scan($location, $languageId, $cached);
        } catch (InvalidSourceIndexEntry $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->logger->error($error, \sprintf('Source file "%s"', $location->relativePath));

            throw new SourceIndexFileException($location->relativePath, $error);
        }
    }

    /** @return \Generator<string, array{location: SourceIndexFileLocation, languageId: string}> */
    private function sourceFiles(Project $project, Cancellation $cancellation): \Generator
    {
        $fileCount = 0;
        foreach ($this->files->files($project) as $path) {
            if (0 === ++$fileCount % 64) {
                delay(0, cancellation: $cancellation);
            }
            $cancellation->throwIfRequested();
            $relativePath = $this->files->relativePath($project, $path);
            if (null === $relativePath) {
                continue;
            }
            $location = new SourceIndexFileLocation($project, $this->uri($project, $relativePath), $path, $relativePath);
            $owner = $this->projects->forDocumentUri($location->uri);
            if (null !== $owner && $owner->rootPath !== $project->rootPath) {
                continue;
            }
            $languageId = $this->files->languageId($location->path);
            if (null !== $languageId) {
                yield $location->relativePath => ['location' => $location, 'languageId' => $languageId];
            }
        }
    }

    /** @param array<array-key, mixed> $params */
    private function uriParameter(array $params): ?string
    {
        $textDocument = $params['textDocument'] ?? null;

        return \is_array($textDocument) && \is_string($textDocument['uri'] ?? null) ? $textDocument['uri'] : null;
    }

    private function uri(Project $project, string $relativePath): string
    {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return rtrim($project->rootUri, '/').'/'.$encodedPath;
    }
}
