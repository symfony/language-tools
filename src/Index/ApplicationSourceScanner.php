<?php

namespace Symfony\Lsp\Index;

use Amp\Cancellation;
use Amp\CancelledException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Progress\ProgressReporterInterface;
use Symfony\Lsp\Project\GitignoreMatcher;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

use function Amp\delay;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class ApplicationSourceScanner
{
    public const EXCLUDED_DIRECTORIES = [
        '.git',
        'node_modules',
        'var',
        'vendor',
    ];

    private const LANGUAGE_IDS = [
        'js' => 'javascript',
        'json' => 'json',
        'mjs' => 'javascript',
        'php' => 'php',
        'ts' => 'typescript',
        'twig' => 'twig',
        'xlf' => 'xml',
        'xliff' => 'xml',
        'yaml' => 'yaml',
        'yml' => 'yaml',
    ];

    private const LOCK_FILES = [
        'npm-shrinkwrap.json',
        'package-lock.json',
        'pnpm-lock.yaml',
    ];

    /** @var list<SourceIndexProviderInterface> */
    private array $providers;

    /** @var array<string, array<string, SourceIndexMetadata>> */
    private array $entries = [];

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
        private readonly GitignoreMatcher $gitignore,
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
            $this->refreshProject($project, $cancellation);
        }
    }

    public function refreshProject(Project $project, ?Cancellation $cancellation = null): void
    {
        $this->statuses->sourceIndexing($project);
        $progress = $this->progress->begin('Symfony source index', $project->rootPath());
        $progressMessage = 'Source index ready';

        try {
            $cached = $this->store->loadMetadata($project);
            try {
                $entries = $this->scan($project, $cached, $cancellation);
            } catch (InvalidSourceIndexEntry) {
                $entries = $this->scan($project, [], $cancellation);
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
        } catch (\Throwable) {
            $progressMessage = 'Source indexing failed';
            $this->statuses->sourceFailed($project);
        } finally {
            $this->progress->end($progress, $progressMessage);
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function updateOpenDocument(array $params): void
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
        if (!$this->belongsToProject($project, $path) || $this->gitignoreExcluded($project->rootPath(), $path)) {
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
        if (null === $project || null === $path || !$this->belongsToProject($project, $path)) {
            return SourceFileChange::untracked();
        }

        $relativePath = $this->relativePath($project, $path);
        if (null === $relativePath) {
            return SourceFileChange::untracked();
        }

        $projectKey = $project->rootPath();
        $indexed = \array_key_exists($projectKey, $this->entries);
        $entries = $indexed ? $this->entries[$projectKey] : $this->store->loadMetadata($project);
        if ($this->gitignoreExcluded($project->rootPath(), $path)) {
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
        } elseif (null === $languageId = $this->languageId($path)) {
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
     * @param array<string, SourceIndexMetadata> $cached
     *
     * @return array<string, SourceIndexMetadata>
     */
    private function scan(Project $project, array $cached, ?Cancellation $cancellation): array
    {
        foreach ($this->providers as $provider) {
            $provider->begin($project);
        }

        $writer = $this->store->beginRewrite($project);
        try {
            // Threshold-triggered cycle collection is quadratic over a scan: parser
            // ASTs are cyclic, so collections fire every few files and each one
            // walks the ever-growing live facts graph. Collect on a fixed file
            // cadence instead.
            $gcWasEnabled = gc_enabled();
            if ($gcWasEnabled) {
                gc_disable();
            }

            try {
                $entries = $this->scanSourceFiles($project, $cached, $writer, $cancellation, $gcWasEnabled);
            } finally {
                if ($gcWasEnabled) {
                    gc_collect_cycles();
                    gc_enable();
                }
            }

            foreach ($this->providers as $provider) {
                $provider->finish($project);
            }

            $writer->commit();

            return $entries;
        } catch (\Throwable $error) {
            $writer->abort();

            throw $error;
        }
    }

    /**
     * @param array<string, SourceIndexMetadata> $cached
     *
     * @return array<string, SourceIndexMetadata>
     */
    private function scanSourceFiles(Project $project, array $cached, SourceIndexWriterInterface $writer, ?Cancellation $cancellation, bool $collectCycles): array
    {
        $entries = [];
        $fileCount = 0;
        $parsedCount = 0;
        foreach ($this->sourceFiles($project->rootPath()) as $path) {
            if (0 === ++$fileCount % 64) {
                delay(0, cancellation: $cancellation);
            }
            $cancellation?->throwIfRequested();
            $relativePath = $this->relativePath($project, $path);
            $languageId = $this->languageId($path);
            if (null === $relativePath || null === $languageId) {
                continue;
            }

            $cachedEntry = $cached[$relativePath] ?? null;
            if (null !== $cachedEntry && $this->isFresh($path, $languageId, $cachedEntry)) {
                try {
                    $payloads = $this->store->loadPayloads($project, $relativePath);
                    foreach ($this->providers as $provider) {
                        $payload = $payloads[$provider->name()] ?? null;
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
                $entries[$relativePath] = $cachedEntry;
                $writer->add($relativePath, $cachedEntry, $payloads);
                continue;
            }

            $text = file_get_contents($path);
            if (false === $text) {
                continue;
            }
            $document = new SourceDocument($this->uri($project, $path), $languageId, $text);
            $payloads = [];
            foreach ($this->providers as $provider) {
                $payloads[$provider->name()] = $this->encodePayload($provider, $provider->index($project, $document));
            }
            $runtimeStructure = $this->runtimeStructureHasher->hash($relativePath, $text);
            $entries[$relativePath] = $this->entry($path, $languageId, hash('sha256', $text), $runtimeStructure);
            $writer->add($relativePath, $entries[$relativePath], $payloads);
            // Only parsing produces cyclic garbage; restores must not pay for
            // full walks of the live facts graph.
            if ($collectCycles && 0 === ++$parsedCount % 256) {
                gc_collect_cycles();
            }
        }

        return $entries;
    }

    /** @return \Generator<int, string> */
    private function sourceFiles(string $directory): \Generator
    {
        if (!is_dir($directory)) {
            return;
        }

        $dotenvPaths = [];
        $files = (new Finder())
            ->files()
            ->in($directory)
            ->exclude(self::EXCLUDED_DIRECTORIES)
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->ignoreUnreadableDirs()
            ->filter(fn (\SplFileInfo $file): bool => null !== $this->languageId($file->getPathname()));
        foreach ($this->gitignore->filter($files, $directory) as $path) {
            if ('dotenv' === $this->languageId($path)) {
                $dotenvPaths[$path] = true;
            }
            yield $path;
        }

        foreach (glob($directory.'/.env*') ?: [] as $path) {
            if (is_file($path) && !isset($dotenvPaths[$path])) {
                yield $path;
            }
        }
    }

    private function gitignoreExcluded(string $rootPath, string $path): bool
    {
        // Symfony reads project-root dotenv files even when they are gitignored
        if ('dotenv' === $this->languageId($path) && Path::canonicalize($rootPath) === \dirname(Path::canonicalize($path))) {
            return false;
        }

        return $this->gitignore->isIgnored($rootPath, $path);
    }

    private function languageId(string $path): ?string
    {
        $basename = basename($path);
        if (str_starts_with($basename, '.env')) {
            return 'dotenv';
        }
        if (\in_array($basename, self::LOCK_FILES, true)) {
            return null;
        }

        $extension = Path::getExtension($path, true);
        if (\in_array($extension, ['js', 'mjs', 'ts'], true) && !str_contains('/'.Path::canonicalize($path), '/assets/')) {
            return null;
        }

        return self::LANGUAGE_IDS[$extension] ?? null;
    }

    /**
     * @param SourceIndexMetadata $entry
     */
    private function isFresh(string $path, string $languageId, array $entry): bool
    {
        return $languageId === $entry['languageId']
            && filesize($path) === $entry['size']
            && filemtime($path) === $entry['modifiedAt'];
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

    private function belongsToProject(Project $project, string $path): bool
    {
        $relativePath = $this->relativePath($project, $path);
        if (null === $relativePath) {
            return false;
        }

        foreach (explode('/', $relativePath) as $part) {
            if (\in_array($part, self::EXCLUDED_DIRECTORIES, true)) {
                return false;
            }
        }

        return true;
    }

    private function relativePath(Project $project, string $path): ?string
    {
        $root = Path::canonicalize($project->rootPath());
        $path = Path::canonicalize($path);
        if (!Path::isBasePath($root, $path) || $root === $path) {
            return null;
        }

        return Path::makeRelative($path, $root);
    }

    private function uri(Project $project, string $path): string
    {
        $relativePath = $this->relativePath($project, $path);
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
