<?php

namespace Symfony\Lsp\Index;

use Amp\Cancellation;
use Amp\CancelledException;
use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Progress\ProgressReporterInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

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

    /** @var array<string, array<string, array{size: int, modifiedAt: int, hash: string, languageId: string, providers: array<string, string>}>> */
    private array $entries = [];

    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly DocumentStore $documents,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly ProgressReporterInterface $progress,
        private readonly SourceIndexStoreInterface $store,
        private readonly SourceIndexPayloadCodec $codec,
        SourceIndexProviderInterface ...$providers,
    ) {
        $names = array_map(static fn (SourceIndexProviderInterface $provider): string => $provider->name(), $providers);
        if (\count($names) !== \count(array_unique($names))) {
            throw new \InvalidArgumentException('Source index provider names must be unique.');
        }

        $this->providers = array_values($providers);
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
    public function refreshAfterSave(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $this->refreshUri($textDocument['uri']);
    }

    public function refreshUri(string $uri, bool $deleted = false): void
    {
        $project = $this->projects->forDocumentUri($uri);
        $path = $this->path($uri);
        if (null === $project || null === $path || !$this->belongsToProject($project, $path)) {
            return;
        }

        $relativePath = $this->relativePath($project, $path);
        if (null === $relativePath) {
            return;
        }

        $entries = $this->entries[$project->rootPath()] ?? $this->store->load($project);
        if ($deleted || !is_file($path) || null === $languageId = $this->languageId($path)) {
            foreach ($this->providers as $provider) {
                $provider->remove($project, $uri);
            }
            unset($entries[$relativePath]);
        } else {
            $text = file_get_contents($path);
            if (false === $text) {
                return;
            }
            $document = new SourceDocument($uri, $languageId, $text);
            $payloads = [];
            foreach ($this->providers as $provider) {
                $payloads[$provider->name()] = $this->codec->encode($provider->replace($project, $document));
            }
            $entries[$relativePath] = $this->entry($path, $languageId, hash('sha256', $text), $payloads);
        }

        $this->entries[$project->rootPath()] = $entries;
        $this->store->save($project, $entries);
        $this->statuses->sourceReady($project);
    }

    /**
     * @param array<string, array{size: int, modifiedAt: int, hash: string, languageId: string, providers: array<string, string>}> $cached
     *
     * @return array<string, array{size: int, modifiedAt: int, hash: string, languageId: string, providers: array<string, string>}>
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
            $entries[$relativePath] = $this->entry($path, $languageId, hash('sha256', $text), $payloads);
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

        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                if (!$file->isLink() && !\in_array($file->getFilename(), self::EXCLUDED_DIRECTORIES, true)) {
                    yield from $this->sourceFiles($file->getPathname());
                }

                continue;
            }

            if ($file->isFile() && null !== $this->languageId($file->getPathname())) {
                yield $file->getPathname();
            }
        }
    }

    private function languageId(string $path): ?string
    {
        if (str_starts_with(basename($path), '.env')) {
            return 'dotenv';
        }

        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if (\in_array($extension, ['js', 'mjs', 'ts'], true) && !str_contains(str_replace('\\', '/', $path), '/assets/')) {
            return null;
        }

        return self::LANGUAGE_IDS[$extension] ?? null;
    }

    /**
     * @param array{size: int, modifiedAt: int, hash: string, languageId: string, providers: array<string, string>} $entry
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
     * @return array{size: int, modifiedAt: int, hash: string, languageId: string, providers: array<string, string>}
     */
    private function entry(string $path, string $languageId, string $hash, array $providers): array
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
            'providers' => $providers,
        ];
    }

    private function belongsToProject(Project $project, string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $project->rootPath()), '/').'/';
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $root)) {
            return false;
        }

        $relativePath = substr($path, \strlen($root));
        foreach (explode('/', $relativePath) as $part) {
            if (\in_array($part, self::EXCLUDED_DIRECTORIES, true)) {
                return false;
            }
        }

        return true;
    }

    private function relativePath(Project $project, string $path): ?string
    {
        $root = rtrim(str_replace('\\', '/', $project->rootPath()), '/').'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root) ? substr($path, \strlen($root)) : null;
    }

    private function path(string $uri): ?string
    {
        $path = parse_url($uri, \PHP_URL_PATH);

        return \is_string($path) ? rawurldecode($path) : null;
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
