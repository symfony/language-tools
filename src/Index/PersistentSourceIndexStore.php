<?php

namespace Symfony\Lsp\Index;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectStateInterface;

/**
 * Append-friendly JSON Lines index where the last record for a path wins.
 *
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class PersistentSourceIndexStore implements SourceIndexStoreInterface, ProjectStateInterface
{
    /** @var array<string, array<string, array{int, int}>> project root => path => [offset, length] */
    private array $offsets = [];

    /** @var array<string, bool> project roots whose file must be reset before appending */
    private array $needsReset = [];

    public function __construct(
        private readonly string $serverVersion,
        private readonly Filesystem $filesystem,
        private readonly SourceIndexJsonLinesCodec $codec,
    ) {
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
            $this->offsets[$project->rootPath] ?? [],
            $this->codec,
        );
    }

    public function loadPayloads(Project $project, string $relativePath): array
    {
        $root = $project->rootPath;
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
            $record = $this->codec->decodeRecord((string) fread($handle, $length));
            if (null === $record || $record['path'] !== $relativePath || null === $record['metadata'] || null === $record['payloads']) {
                throw new \UnexpectedValueException('The source index record is corrupted.');
            }

            return $record['payloads'];
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
            return new PersistentSourceIndexWriter($this, $this->codec, $project, null, $temporaryPath);
        }
        $handle = @fopen($temporaryPath, 'w');

        return new PersistentSourceIndexWriter(
            $this,
            $this->codec,
            $project,
            \is_resource($handle) ? $handle : null,
            $temporaryPath,
        );
    }

    public function append(Project $project, string $relativePath, array $metadata, array $payloads): void
    {
        $this->appendLine($project, $relativePath, $this->codec->encodeRecord($relativePath, $metadata, $payloads));
    }

    public function appendDeletion(Project $project, string $relativePath): void
    {
        $this->appendLine($project, $relativePath, $this->codec->encodeDeletion($relativePath));
        unset($this->offsets[$project->rootPath][$relativePath]);
    }

    public function path(Project $project): string
    {
        return Path::join($project->rootPath, 'var/symfony-lsp', $this->serverVersion, 'index/source.jsonl');
    }

    /**
     * @param array<string, array{int, int}> $offsets
     */
    public function replaceGeneration(Project $project, string $temporaryPath, array $offsets): void
    {
        if (false === @rename($temporaryPath, $this->path($project))) {
            @unlink($temporaryPath);

            return;
        }
        $this->offsets[$project->rootPath] = $offsets;
        $this->needsReset[$project->rootPath] = false;
    }

    public function removeProject(Project $project): void
    {
        unset($this->offsets[$project->rootPath], $this->needsReset[$project->rootPath]);
    }

    /**
     * @return array{array<string, SourceIndexMetadata>, ?resource}
     */
    private function readMetadata(Project $project): array
    {
        $root = $project->rootPath;
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
        if (false === $header || !$this->codec->validHeader($header)) {
            fclose($handle);

            return [[], null];
        }
        $this->needsReset[$root] = false;

        $metadata = [];
        $offset = \strlen($header);
        while (false !== ($line = fgets($handle))) {
            $length = \strlen($line);
            $record = $this->codec->decodeMetadata($line);
            if (null === $record) {
                if (!$writable || !ftruncate($handle, $offset)) {
                    $this->needsReset[$root] = true;
                }
                break;
            }
            $relativePath = $record['path'];
            if (null === $record['metadata']) {
                unset($metadata[$relativePath], $this->offsets[$root][$relativePath]);
            } else {
                $metadata[$relativePath] = $record['metadata'];
                $this->offsets[$root][$relativePath] = [$offset, $length];
            }
            $offset += $length;
        }

        return [$metadata, $handle];
    }

    private function appendLine(Project $project, string $relativePath, string $line): void
    {
        $root = $project->rootPath;
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
                fwrite($handle, $this->codec->encodeHeader());
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
}
