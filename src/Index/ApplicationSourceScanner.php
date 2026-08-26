<?php

namespace Symfony\Lsp\Index;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\Sync\KeyedMutex;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Progress\ProgressReporterInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\ProjectStateInterface;
use Symfony\Lsp\Project\UriToPathConverter;
use Symfony\Lsp\Server\ServerLogger;

use function Amp\delay;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 * @phpstan-import-type SourceIndexRecord from SourceIndexReaderInterface
 */
final class ApplicationSourceScanner implements ProjectStateInterface
{
    private const LOCK_PREFIX = "source\0";

    /** @var list<SourceIndexProviderInterface> */
    private array $providers;

    /** @var array<string, array<string, SourceIndexMetadata>> */
    private array $entries = [];

    /** @var array<string, DeferredCancellation> */
    private array $activeScans = [];

    /** @param iterable<SourceIndexProviderInterface> $providers */
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly DocumentStore $documents,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly ProgressReporterInterface $progress,
        private readonly SourceIndexStoreInterface $store,
        private readonly SourceIndexPayloadCodec $codec,
        private readonly PhpRuntimeStructureHasher $runtimeStructureHasher,
        private readonly UriToPathConverter $uriToPathConverter,
        private readonly SourceFileEnumerator $files,
        private readonly KeyedMutex $mutex,
        private readonly ServerLogger $logger,
        iterable $providers,
    ) {
        $providers = \is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
        $this->codec->validate($providers);
        $this->providers = $providers;
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
                // a superseded scan aborts only its own project
            }
        }
    }

    public function refreshProject(Project $project, ?Cancellation $cancellation = null): void
    {
        $root = $project->rootPath();
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
        $root = $project->rootPath();
        ($this->activeScans[$root] ?? null)?->cancel();
        unset($this->activeScans[$root], $this->entries[$root]);
    }

    public function indexedHash(Project $project, string $path): ?string
    {
        $relativePath = $this->files->relativePath($project, $path);

        return null === $relativePath ? null : ($this->entries[$project->rootPath()][$relativePath]['hash'] ?? null);
    }

    private function refreshProjectUnlocked(Project $project, Cancellation $cancellation): void
    {
        $this->statuses->sourceIndexing($project);
        $progress = $this->progress->begin('Symfony source index', $project->rootPath());
        $progressMessage = 'Source index ready';

        try {
            try {
                $entries = $this->scan($project, $this->store->beginRead($project), $cancellation);
            } catch (InvalidSourceIndexEntry) {
                $entries = $this->scan($project, null, $cancellation);
            }
            $this->entries[$project->rootPath()] = $entries;
            foreach ($this->documents->all() as $document) {
                if ($this->projects->forDocumentUri($document->uri())?->rootPath() === $project->rootPath()) {
                    $this->updateOpenDocument(['textDocument' => ['uri' => $document->uri()]]);
                }
            }
            $this->statuses->sourceReady($project);
        } catch (CancelledException $error) {
            $progressMessage = 'Source indexing canceled';

            throw $error;
        } catch (\Throwable $error) {
            $progressMessage = 'Source indexing failed';
            $this->logger->error($error);
            $this->statuses->sourceFailed($project);
        } finally {
            $this->progress->end($progress, $progressMessage);
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function updateOpenDocument(array $params, bool $includeExcluded = false): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        $path = $this->uriToPathConverter->convert($textDocument['uri']);
        if (null === $document || null === $project || null === $path) {
            return;
        }
        if (!$this->files->belongsToProject($project, $path)
            || (!$includeExcluded && $this->files->isExcluded($project, $path))
            || $this->files->gitignoreExcluded($project->rootPath(), $path)
        ) {
            foreach ($this->providers as $provider) {
                $provider->removeOverlay($project, $document->uri());
            }

            return;
        }

        foreach ($this->providers as $provider) {
            $provider->overlay($project, $document);
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function restoreClosedDocument(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $project) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->removeOverlay($project, $textDocument['uri']);
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function refreshAfterSave(array $params): SourceFileChange
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return SourceFileChange::untracked();
        }

        return $this->refreshUri($textDocument['uri']);
    }

    public function refreshUri(string $uri, bool $deleted = false): SourceFileChange
    {
        $project = $this->projects->forDocumentUri($uri);
        $path = $this->uriToPathConverter->convert($uri);
        if (null === $project || null === $path || !$this->files->belongsToProject($project, $path)) {
            return SourceFileChange::untracked();
        }

        $relativePath = $this->files->relativePath($project, $path);
        if (null === $relativePath) {
            return SourceFileChange::untracked();
        }

        $lock = $this->mutex->acquire(self::LOCK_PREFIX.$project->rootPath());
        try {
            return $this->refreshUriUnlocked($project, $uri, $path, $relativePath, $deleted);
        } finally {
            $lock->release();
        }
    }

    private function refreshUriUnlocked(Project $project, string $uri, string $path, string $relativePath, bool $deleted): SourceFileChange
    {
        // the project can be removed from the workspace while waiting for the lock
        if ($this->projects->forDocumentUri($uri)?->rootPath() !== $project->rootPath()) {
            return SourceFileChange::untracked();
        }

        $projectKey = $project->rootPath();
        $indexed = \array_key_exists($projectKey, $this->entries);
        $entries = $indexed ? $this->entries[$projectKey] : $this->store->loadMetadata($project);
        if ($this->files->isExcluded($project, $path) || $this->files->gitignoreExcluded($project->rootPath(), $path)) {
            if (isset($entries[$relativePath])) {
                foreach ($this->providers as $provider) {
                    $provider->remove($project, $uri);
                }
                unset($entries[$relativePath]);
                $this->store->appendDeletion($project, $relativePath);
                $this->entries[$projectKey] = $entries;
                $this->statuses->sourceReady($project);
            }

            return SourceFileChange::ignored();
        }
        $sourceFileChange = SourceFileChange::factsChanged([]);
        if ($deleted || !is_file($path)) {
            if (!isset($entries[$relativePath])) {
                return SourceFileChange::untracked();
            }
            foreach ($this->providers as $provider) {
                $provider->remove($project, $uri);
            }
            unset($entries[$relativePath]);
            $this->store->appendDeletion($project, $relativePath);
        } elseif (null === $languageId = $this->files->languageId($path)) {
            return SourceFileChange::untracked();
        } else {
            $text = file_get_contents($path);
            if (false === $text) {
                return SourceFileChange::untracked();
            }
            $hash = hash('sha256', $text);
            $cachedEntry = $entries[$relativePath] ?? null;
            if ($indexed && null !== $cachedEntry && $languageId === $cachedEntry['languageId'] && $hash === $cachedEntry['hash']) {
                return SourceFileChange::unchanged();
            }

            if (null === $cachedEntry) {
                $sourceFileChange = SourceFileChange::untracked();
            }
            $previousPayloads = [];
            if (null !== $cachedEntry) {
                try {
                    $previousPayloads = $this->store->loadPayloads($project, $relativePath);
                } catch (\UnexpectedValueException) {
                }
            }
            $document = new SourceDocument($uri, $languageId, $text);
            $payloads = [];
            $factsChanged = false;
            $changedProviders = [];
            $requiresFullRuntimeTracking = $this->runtimeStructureHasher->requiresFullRuntimeTracking($relativePath, $text);
            $runtimeStructure = $this->runtimeStructureHasher->hash($relativePath, $text);
            if (null !== $runtimeStructure && $runtimeStructure === ($cachedEntry['runtimeStructure'] ?? null)) {
                $sourceFileChange = SourceFileChange::contentOnly();
            }
            foreach ($this->providers as $provider) {
                $name = $provider->name();
                $data = $provider->replace($project, $document);
                $payloads[$name] = $this->encodePayload($provider, $data);
                $previousPayload = $previousPayloads[$name] ?? null;
                if ($payloads[$name] === $previousPayload) {
                    continue;
                }
                $factsChanged = true;
                if ('' === $previousPayload) {
                    // An empty payload restores nothing, so only new runtime
                    // declarations require a runtime refresh.
                    if ([] === $provider->runtimeDeclarations($data)) {
                        continue;
                    }
                } elseif (\is_string($previousPayload)) {
                    try {
                        $previousData = $this->codec->decode($name, $previousPayload);
                        if (serialize($provider->runtimeDeclarations($data)) === serialize($provider->runtimeDeclarations($previousData))) {
                            continue;
                        }
                    } catch (\UnexpectedValueException) {
                    }
                }
                $changedProviders[] = $name;
            }
            if (null !== $cachedEntry && $sourceFileChange->requiresRuntimeRefresh()) {
                $sourceFileChange = 'php' === $languageId && !$requiresFullRuntimeTracking && $factsChanged && [] === $changedProviders
                    ? SourceFileChange::contentOnly()
                    : SourceFileChange::factsChanged($changedProviders);
            }
            $entries[$relativePath] = $this->entry($path, $languageId, $hash, $runtimeStructure);
            $this->store->append($project, $relativePath, $entries[$relativePath], $payloads);
        }

        $this->entries[$projectKey] = $entries;
        $this->statuses->sourceReady($project);

        return $sourceFileChange;
    }

    /**
     * @return array<string, SourceIndexMetadata>
     */
    private function scan(Project $project, ?SourceIndexReaderInterface $reader, Cancellation $cancellation): array
    {
        $writer = null;
        try {
            foreach ($this->providers as $provider) {
                $provider->begin($project);
            }

            $writer = $this->store->beginRewrite($project);
            // Threshold-triggered cycle collection is quadratic over a scan: parser
            // ASTs are cyclic, so collections fire every few files and each one
            // walks the ever-growing live facts graph. Collect on a fixed file
            // cadence instead.
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
            foreach ($this->providers as $provider) {
                $provider->finish($project);
            }

            $writer->commit();

            return $entries;
        } catch (\Throwable $error) {
            $writer?->abort();

            throw $error;
        } finally {
            $reader?->close();
        }
    }

    /**
     * @return array<string, SourceIndexMetadata>
     */
    private function scanSourceFiles(Project $project, ?SourceIndexReaderInterface $reader, SourceIndexWriterInterface $writer, Cancellation $cancellation, bool $collectCycles): array
    {
        $entries = [];
        $parsedCount = 0;
        if (null === $reader || !$reader->hasRecords()) {
            foreach ($this->sourceFiles($project, $cancellation) as $relativePath => $source) {
                [$entry, $parsed] = $this->scanSourceFile($project, $relativePath, $source['path'], $source['languageId'], null, $writer);
                if (null !== $entry) {
                    $entries[$relativePath] = $entry;
                }
                if ($parsed && $collectCycles && 0 === ++$parsedCount % 256) {
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
            [$entry, $parsed] = $this->scanSourceFile($project, $relativePath, $source['path'], $source['languageId'], $cached, $writer);
            if (null !== $entry) {
                $entries[$relativePath] = $entry;
            }
            if ($parsed && $collectCycles && 0 === ++$parsedCount % 256) {
                gc_collect_cycles();
            }
        }
        foreach ($sources as $relativePath => $source) {
            if (0 === ++$processedCount % 64) {
                delay(0, cancellation: $cancellation);
            }
            $cancellation->throwIfRequested();
            [$entry, $parsed] = $this->scanSourceFile($project, $relativePath, $source['path'], $source['languageId'], null, $writer);
            if (null !== $entry) {
                $entries[$relativePath] = $entry;
            }
            if ($parsed && $collectCycles && 0 === ++$parsedCount % 256) {
                gc_collect_cycles();
            }
        }

        return $entries;
    }

    /**
     * @return \Generator<string, array{path: string, languageId: string}>
     */
    private function sourceFiles(Project $project, Cancellation $cancellation): \Generator
    {
        $fileCount = 0;
        foreach ($this->files->files($project) as $path) {
            if (0 === ++$fileCount % 64) {
                delay(0, cancellation: $cancellation);
            }
            $cancellation->throwIfRequested();
            $uri = $this->uri($project, $path);
            $owner = $this->projects->forDocumentUri($uri);
            if (null !== $owner && $owner->rootPath() !== $project->rootPath()) {
                continue;
            }
            $relativePath = $this->files->relativePath($project, $path);
            $languageId = $this->files->languageId($path);
            if (null !== $relativePath && null !== $languageId) {
                yield $relativePath => ['path' => $path, 'languageId' => $languageId];
            }
        }
    }

    /**
     * @param ?SourceIndexRecord $cached
     *
     * @return array{?SourceIndexMetadata, bool}
     */
    private function scanSourceFile(Project $project, string $relativePath, string $path, string $languageId, ?array $cached, SourceIndexWriterInterface $writer): array
    {
        $text = file_get_contents($path);
        if (false === $text) {
            return [null, false];
        }
        $hash = hash('sha256', $text);
        if (null !== $cached && $this->isFresh($path, $languageId, $hash, $cached['metadata'])) {
            try {
                foreach ($this->providers as $provider) {
                    $payload = $cached['payloads'][$provider->name()] ?? null;
                    if (!\is_string($payload)) {
                        throw new \UnexpectedValueException('A source index provider payload is missing.');
                    }
                    if ('' === $payload) {
                        continue;
                    }
                    $provider->restore($project, $this->codec->decode($provider->name(), $payload));
                }
            } catch (\Throwable $error) {
                throw new InvalidSourceIndexEntry(previous: $error);
            }
            $writer->add($relativePath, $cached['metadata'], $cached['payloads']);

            return [$cached['metadata'], false];
        }

        $document = new SourceDocument($this->uri($project, $path), $languageId, $text);
        $payloads = [];
        foreach ($this->providers as $provider) {
            $payloads[$provider->name()] = $this->encodePayload($provider, $provider->index($project, $document));
        }
        $runtimeStructure = $this->runtimeStructureHasher->hash($relativePath, $text);
        $entry = $this->entry($path, $languageId, $hash, $runtimeStructure);
        $writer->add($relativePath, $entry, $payloads);

        return [$entry, true];
    }

    /**
     * @param SourceIndexMetadata $entry
     */
    private function isFresh(string $path, string $languageId, string $hash, array $entry): bool
    {
        return $languageId === $entry['languageId']
            && filesize($path) === $entry['size']
            && filemtime($path) === $entry['modifiedAt']
            && $hash === $entry['hash'];
    }

    /**
     * @return SourceIndexMetadata
     */
    private function entry(string $path, string $languageId, string $hash, ?string $runtimeStructure): array
    {
        $size = filesize($path);
        $modifiedAt = filemtime($path);
        if (false === $size || false === $modifiedAt) {
            throw new \RuntimeException(\sprintf('Unable to read source metadata for "%s".', $path));
        }

        return [
            'size' => $size,
            'modifiedAt' => $modifiedAt,
            'hash' => $hash,
            'languageId' => $languageId,
            'runtimeStructure' => $runtimeStructure,
        ];
    }

    private function encodePayload(SourceIndexProviderInterface $provider, ?SourceFactsInterface $facts): string
    {
        return null === $facts || $facts->isEmpty() ? '' : $this->codec->encode($provider->name(), $facts);
    }

    private function uri(Project $project, string $path): string
    {
        $relativePath = $this->files->relativePath($project, $path);
        if (null === $relativePath) {
            throw new \InvalidArgumentException('The source path is outside the project root.');
        }
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return rtrim($project->rootUri(), '/').'/'.$encodedPath;
    }
}

final class InvalidSourceIndexEntry extends \RuntimeException
{
}
