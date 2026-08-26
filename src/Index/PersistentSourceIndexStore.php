<?php

namespace Symfony\Lsp\Index;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectStateInterface;

/**
 * Append-friendly JSON Lines index: a header line, then one record per file
 * where the last record for a path wins. Full scans stream a new generation
 * file and swap it in atomically; single-file refreshes append one record.
 *
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class PersistentSourceIndexStore implements SourceIndexStoreInterface, ProjectStateInterface
{
    private const SCHEMA_VERSION = 6;

    /** @var array<string, array<string, array{int, int}>> project root => path => [offset, length] */
    private array $offsets = [];

    /** @var array<string, bool> project roots whose file must be reset before appending */
    private array $needsReset = [];

    public function __construct(private readonly string $serverVersion, private readonly Filesystem $filesystem)
    {
    }

    public function loadMetadata(Project $project): array
    {
        [$metadata, $handle] = $this->readMetadata($project);
        if (null !== $handle) {
            fclose($handle);
        }

        return $metadata;
    }

    public function beginRead(Project $project): SourceIndexReaderInterface
    {
        [$metadata, $handle] = $this->readMetadata($project);

        return new PersistentSourceIndexReader(
            $handle,
            $metadata,
            $this->offsets[$project->rootPath()] ?? [],
        );
    }

    public function loadPayloads(Project $project, string $relativePath): array
    {
        $root = $project->rootPath();
        if (!isset($this->offsets[$root])) {
            $this->loadMetadata($project);
        }
        $position = $this->offsets[$root][$relativePath] ?? null;
        if (null === $position) {
            return [];
        }
        $handle = @fopen($this->path($project), 'r');
        if (false === $handle) {
            return [];
        }

        try {
            [$offset, $length] = $position;
            if ($length < 1 || 0 !== fseek($handle, $offset)) {
                throw new \UnexpectedValueException('The source index record is unreadable.');
            }
            $line = (string) fread($handle, $length);
            $record = $this->decodeRecord($line);
            if (null === $record || $record[0] !== $relativePath || null === $record[1]) {
                throw new \UnexpectedValueException('The source index record is corrupted.');
            }

            return $this->decodePayloads($line);
        } finally {
            fclose($handle);
        }
    }

    public function beginRewrite(Project $project): SourceIndexWriterInterface
    {
        $path = $this->path($project);
        $temporaryPath = $path.'.tmp';
        try {
            $this->filesystem->mkdir(\dirname($path));
        } catch (IOExceptionInterface) {
            return new PersistentSourceIndexWriter($this, $project, null, $path, $temporaryPath);
        }
        $handle = @fopen($temporaryPath, 'w');
        if (\is_resource($handle)) {
            fwrite($handle, $this->header());
        }

        return new PersistentSourceIndexWriter($this, $project, \is_resource($handle) ? $handle : null, $path, $temporaryPath);
    }

    public function append(Project $project, string $relativePath, array $metadata, array $payloads): void
    {
        $this->appendLine($project, $relativePath, $this->encodeRecord($relativePath, $metadata, $payloads));
    }

    public function appendDeletion(Project $project, string $relativePath): void
    {
        $root = $project->rootPath();
        $this->appendLine($project, $relativePath, json_encode(['path' => $relativePath, 'deleted' => true], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n");
        unset($this->offsets[$root][$relativePath]);
    }

    /**
     * @param SourceIndexMetadata   $metadata
     * @param array<string, string> $payloads
     */
    public function encodeRecord(string $relativePath, array $metadata, array $payloads): string
    {
        return json_encode(
            ['path' => $relativePath, ...$metadata, 'providers' => (object) $payloads],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        )."\n";
    }

    public function header(): string
    {
        return json_encode(
            ['schemaVersion' => self::SCHEMA_VERSION, 'serverVersion' => $this->serverVersion],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        )."\n";
    }

    public function path(Project $project): string
    {
        return Path::join($project->rootPath(), 'var/symfony-lsp', $this->serverVersion, 'index/source.jsonl');
    }

    /**
     * @param array<string, array{int, int}> $offsets
     */
    public function replaceOffsets(Project $project, array $offsets): void
    {
        $this->offsets[$project->rootPath()] = $offsets;
        $this->needsReset[$project->rootPath()] = false;
    }

    public function removeProject(Project $project): void
    {
        unset($this->offsets[$project->rootPath()], $this->needsReset[$project->rootPath()]);
    }

    /**
     * @return array{array<string, SourceIndexMetadata>, ?resource}
     */
    private function readMetadata(Project $project): array
    {
        $root = $project->rootPath();
        $this->offsets[$root] = [];
        $this->needsReset[$root] = true;
        $path = $this->path($project);
        if (!is_file($path)) {
            return [[], null];
        }
        $writable = false;
        $handle = @fopen($path, 'r+');
        if (false === $handle) {
            $handle = @fopen($path, 'r');
        } else {
            $writable = true;
        }
        if (false === $handle) {
            return [[], null];
        }

        $header = fgets($handle);
        if (false === $header || !$this->validHeader($header)) {
            fclose($handle);

            return [[], null];
        }
        $this->needsReset[$root] = false;

        $metadata = [];
        $offset = \strlen($header);
        while (false !== ($line = fgets($handle))) {
            $length = \strlen($line);
            $record = $this->decodeRecord($line);
            if (null === $record) {
                if (!$writable || !ftruncate($handle, $offset)) {
                    $this->needsReset[$root] = true;
                }
                break;
            }
            [$relativePath, $entry] = $record;
            if (null === $entry) {
                unset($metadata[$relativePath], $this->offsets[$root][$relativePath]);
            } else {
                $metadata[$relativePath] = $entry;
                $this->offsets[$root][$relativePath] = [$offset, $length];
            }
            $offset += $length;
        }

        return [$metadata, $handle];
    }

    private function appendLine(Project $project, string $relativePath, string $line): void
    {
        $root = $project->rootPath();
        if (!isset($this->offsets[$root])) {
            $this->loadMetadata($project);
        }
        $path = $this->path($project);
        $reset = ($this->needsReset[$root] ?? true) || !is_file($path);
        try {
            $this->filesystem->mkdir(\dirname($path));
        } catch (IOExceptionInterface) {
            return;
        }
        $handle = @fopen($path, $reset ? 'wb' : 'ab');
        if (false === $handle) {
            return;
        }

        try {
            if ($reset) {
                fwrite($handle, $this->header());
                $this->offsets[$root] = [];
                $this->needsReset[$root] = false;
            }
            fwrite($handle, $line);
            $end = ftell($handle);
            if (\is_int($end)) {
                $this->offsets[$root][$relativePath] = [$end - \strlen($line), \strlen($line)];
            }
        } finally {
            fclose($handle);
        }
    }

    private function validHeader(string $line): bool
    {
        $header = json_decode($line, true);

        return \is_array($header)
            && self::SCHEMA_VERSION === ($header['schemaVersion'] ?? null)
            && $this->serverVersion === ($header['serverVersion'] ?? null);
    }

    /**
     * @return ?array{string, ?SourceIndexMetadata} a path with its metadata, or null metadata for a deletion
     */
    private function decodeRecord(string $line): ?array
    {
        if (!str_ends_with($line, "\n")) {
            return null;
        }
        $record = json_decode($line, true);
        if (!\is_array($record) || !\is_string($record['path'] ?? null)) {
            return null;
        }
        if (true === ($record['deleted'] ?? null)) {
            return [$record['path'], null];
        }
        $runtimeStructure = $record['runtimeStructure'] ?? null;
        if (!\is_int($record['size'] ?? null)
            || !\is_int($record['modifiedAt'] ?? null)
            || !\is_string($record['hash'] ?? null)
            || 64 !== \strlen($record['hash'])
            || !\is_string($record['languageId'] ?? null)
            || (null !== $runtimeStructure && !\is_string($runtimeStructure))
            || !\is_array($record['providers'] ?? null)
        ) {
            return null;
        }

        return [$record['path'], [
            'size' => $record['size'],
            'modifiedAt' => $record['modifiedAt'],
            'hash' => $record['hash'],
            'languageId' => $record['languageId'],
            'runtimeStructure' => $runtimeStructure,
        ]];
    }

    /**
     * @return array<string, string>
     */
    private function decodePayloads(string $line): array
    {
        $record = json_decode($line, true);
        if (!\is_array($record) || !\is_array($record['providers'] ?? null)) {
            throw new \UnexpectedValueException('The source index record is corrupted.');
        }
        $payloads = [];
        foreach ($record['providers'] as $name => $payload) {
            if (!\is_string($name) || !\is_string($payload)) {
                throw new \UnexpectedValueException('A source index provider payload is invalid.');
            }
            $payloads[$name] = $payload;
        }

        return $payloads;
    }
}

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class PersistentSourceIndexReader implements SourceIndexReaderInterface
{
    /**
     * @param ?resource                          $handle
     * @param array<string, SourceIndexMetadata> $metadata
     * @param array<string, array{int, int}>     $offsets
     */
    public function __construct(
        private $handle,
        private readonly array $metadata,
        private readonly array $offsets,
    ) {
    }

    public function hasRecords(): bool
    {
        return [] !== $this->metadata;
    }

    public function records(): iterable
    {
        if (null === $this->handle) {
            return;
        }
        if (!rewind($this->handle) || false === $header = fgets($this->handle)) {
            throw new \UnexpectedValueException('The source index is unreadable.');
        }

        $offset = \strlen($header);
        while (false !== ($line = fgets($this->handle))) {
            $length = \strlen($line);
            $record = json_decode($line, true);
            $relativePath = \is_array($record) ? ($record['path'] ?? null) : null;
            if (!\is_string($relativePath)) {
                throw new \UnexpectedValueException('The source index record is corrupted.');
            }
            if (($this->offsets[$relativePath] ?? null) !== [$offset, $length]) {
                $offset += $length;
                continue;
            }
            $metadata = $this->metadata[$relativePath] ?? null;
            $providers = $record['providers'] ?? null;
            if (null === $metadata || !\is_array($providers)) {
                throw new \UnexpectedValueException('The source index record is corrupted.');
            }
            $payloads = [];
            foreach ($providers as $name => $payload) {
                if (!\is_string($name) || !\is_string($payload)) {
                    throw new \UnexpectedValueException('A source index provider payload is invalid.');
                }
                $payloads[$name] = $payload;
            }

            yield $relativePath => ['metadata' => $metadata, 'payloads' => $payloads];
            $offset += $length;
        }
    }

    public function close(): void
    {
        if (null === $this->handle) {
            return;
        }
        fclose($this->handle);
        $this->handle = null;
    }
}

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class PersistentSourceIndexWriter implements SourceIndexWriterInterface
{
    /** @var array<string, array{int, int}> */
    private array $offsets = [];
    private int $position = 0;
    private bool $finished = false;

    /**
     * @param ?resource $handle
     */
    public function __construct(
        private readonly PersistentSourceIndexStore $store,
        private readonly Project $project,
        private $handle,
        private readonly string $path,
        private readonly string $temporaryPath,
    ) {
        $this->position = \strlen($store->header());
    }

    public function add(string $relativePath, array $metadata, array $payloads): void
    {
        if ($this->finished || null === $this->handle) {
            return;
        }
        $line = $this->store->encodeRecord($relativePath, $metadata, $payloads);
        if (false === @fwrite($this->handle, $line)) {
            fclose($this->handle);
            $this->handle = null;

            return;
        }
        $this->offsets[$relativePath] = [$this->position, \strlen($line)];
        $this->position += \strlen($line);
    }

    public function commit(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        if (null === $this->handle) {
            return;
        }
        fclose($this->handle);
        $this->handle = null;
        if (false === @rename($this->temporaryPath, $this->path)) {
            @unlink($this->temporaryPath);

            return;
        }
        $this->store->replaceOffsets($this->project, $this->offsets);
    }

    public function abort(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        if (null !== $this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
        @unlink($this->temporaryPath);
    }
}
