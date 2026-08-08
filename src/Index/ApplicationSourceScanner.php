<?php

namespace Symfony\Lsp\Index;

use Amp\Cancellation;
use Amp\CancelledException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Progress\ProgressReporterInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

use function Amp\delay;

final class ApplicationSourceScanner
{
    private const EXCLUDED_DIRECTORIES = [
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

    /** @var list<SourceIndexProviderInterface> */
    private array $providers;

    /** @var array<string, array<string, array{size: int, modifiedAt: int, hash: string, languageId: string, runtimeStructure: ?string, providers: array<string, string>}>> */
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
        iterable $providers,
    ) {
        $providers = \is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
        $names = array_map(static fn (SourceIndexProviderInterface $provider): string => $provider->name(), $providers);
        if (\count($names) !== \count(array_unique($names))) {
            throw new \InvalidArgumentException('Source index provider names must be unique.');
        }

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
            $cached = $this->store->load($project);
            try {
                $entries = $this->scan($project, $cached, $cancellation);
            } catch (InvalidSourceIndexEntry) {
                $entries = $this->scan($project, [], $cancellation);
            }
            $this->entries[$project->rootPath()] = $entries;
            $this->store->save($project, $entries);
            $this->statuses->sourceReady($project);
        } catch (CancelledException $error) {
            $progressMessage = 'Source indexing canceled';

            throw $error;
        } catch (\Throwable $error) {
            $progressMessage = 'Source indexing failed';
            $this->statuses->sourceFailed($project, $error);
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
        if (null === $document || null === $project) {
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
        $entries = $indexed ? $this->entries[$projectKey] : $this->store->load($project);
        $sourceFileChange = SourceFileChange::factsChanged([]);
        if ($deleted || !is_file($path)) {
            if (!isset($entries[$relativePath])) {
                return SourceFileChange::untracked();
            }
            foreach ($this->providers as $provider) {
                $provider->remove($project, $uri);
            }
            unset($entries[$relativePath]);
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
                $payloads[$name] = $this->codec->encode($data);
                $previousPayload = $cachedEntry['providers'][$name] ?? null;
                if ($payloads[$name] === $previousPayload) {
                    continue;
                }
                $factsChanged = true;
                if (\is_string($previousPayload)) {
                    $previousData = $this->codec->decode($previousPayload);
                    if ($this->codec->encode($provider->runtimeDeclarations($data)) === $this->codec->encode($provider->runtimeDeclarations($previousData))) {
                        continue;
                    }
                }
                $changedProviders[] = $name;
            }
            if (null !== $cachedEntry && $sourceFileChange->requiresRuntimeRefresh()) {
                $sourceFileChange = 'php' === $languageId && !$requiresFullRuntimeTracking && $factsChanged && [] === $changedProviders
                    ? SourceFileChange::contentOnly()
                    : SourceFileChange::factsChanged($changedProviders);
            }
            $entries[$relativePath] = $this->entry($path, $languageId, $hash, $runtimeStructure, $payloads);
        }

        $this->entries[$projectKey] = $entries;
        $this->store->save($project, $entries);
        $this->statuses->sourceReady($project);

        return $sourceFileChange;
    }

    /**
     * @param array<string, array{size: int, modifiedAt: int, hash: string, languageId: string, runtimeStructure: ?string, providers: array<string, string>}> $cached
     *
     * @return array<string, array{size: int, modifiedAt: int, hash: string, languageId: string, runtimeStructure: ?string, providers: array<string, string>}>
     */
    private function scan(Project $project, array $cached, ?Cancellation $cancellation): array
    {
        foreach ($this->providers as $provider) {
            $provider->begin($project);
        }

        $entries = [];
        $fileCount = 0;
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
                    foreach ($this->providers as $provider) {
                        $payload = $cachedEntry['providers'][$provider->name()] ?? null;
                        if (!\is_string($payload)) {
                            throw new \UnexpectedValueException('A source index provider payload is missing.');
                        }
                        $provider->restore($project, $this->codec->decode($payload));
                    }
                } catch (\Throwable $error) {
                    throw new InvalidSourceIndexEntry(previous: $error);
                }
                $entries[$relativePath] = $cachedEntry;
                continue;
            }

            $text = file_get_contents($path);
            if (false === $text) {
                continue;
            }
            $document = new SourceDocument($this->uri($project, $path), $languageId, $text);
            $payloads = [];
            foreach ($this->providers as $provider) {
                $payloads[$provider->name()] = $this->codec->encode($provider->index($project, $document));
            }
            $runtimeStructure = $this->runtimeStructureHasher->hash($relativePath, $text);
            $entries[$relativePath] = $this->entry($path, $languageId, hash('sha256', $text), $runtimeStructure, $payloads);
        }

        foreach ($this->providers as $provider) {
            $provider->finish($project);
        }

        return $entries;
    }

    /** @return \Generator<int, string> */
    private function sourceFiles(string $directory): \Generator
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = (new Finder())
            ->files()
            ->in($directory)
            ->exclude(self::EXCLUDED_DIRECTORIES)
            ->ignoreDotFiles(false)
            ->ignoreVCS(false)
            ->filter(fn (\SplFileInfo $file): bool => null !== $this->languageId($file->getPathname()));
        foreach ($files as $file) {
            yield $file->getPathname();
        }
    }

    private function languageId(string $path): ?string
    {
        if (str_starts_with(basename($path), '.env')) {
            return 'dotenv';
        }

        $extension = Path::getExtension($path, true);
        if (\in_array($extension, ['js', 'mjs', 'ts'], true) && !str_contains('/'.Path::canonicalize($path), '/assets/')) {
            return null;
        }

        return self::LANGUAGE_IDS[$extension] ?? null;
    }

    /**
     * @param array{size: int, modifiedAt: int, hash: string, languageId: string, runtimeStructure: ?string, providers: array<string, string>} $entry
     */
    private function isFresh(string $path, string $languageId, array $entry): bool
    {
        return $languageId === $entry['languageId']
            && filesize($path) === $entry['size']
            && filemtime($path) === $entry['modifiedAt'];
    }

    /**
     * @param array<string, string> $providers
     *
     * @return array{size: int, modifiedAt: int, hash: string, languageId: string, runtimeStructure: ?string, providers: array<string, string>}
     */
    private function entry(string $path, string $languageId, string $hash, ?string $runtimeStructure, array $providers): array
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
            'providers' => $providers,
        ];
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
