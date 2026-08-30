<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Index\SourceIndexReaderInterface;
use Symfony\Lsp\Index\SourceIndexStoreInterface;
use Symfony\Lsp\Index\SourceIndexWriterInterface;
use Symfony\Lsp\Project\Project;

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class InMemorySourceIndexStore implements SourceIndexStoreInterface
{
    /** @var array<string, array<string, SourceIndexMetadata>> */
    private array $metadata = [];

    /** @var array<string, array<string, array<string, string>>> */
    private array $payloads = [];

    public function loadMetadata(Project $project): array
    {
        return $this->metadata[$project->rootPath] ?? [];
    }

    public function beginRead(Project $project): SourceIndexReaderInterface
    {
        return new InMemorySourceIndexReader(
            $this->metadata[$project->rootPath] ?? [],
            $this->payloads[$project->rootPath] ?? [],
        );
    }

    public function loadPayloads(Project $project, string $relativePath): array
    {
        return $this->payloads[$project->rootPath][$relativePath] ?? [];
    }

    public function beginRewrite(Project $project): SourceIndexWriterInterface
    {
        return new InMemorySourceIndexWriter($this, $project->rootPath);
    }

    public function append(Project $project, string $relativePath, array $metadata, array $payloads): void
    {
        $this->metadata[$project->rootPath][$relativePath] = $metadata;
        $this->payloads[$project->rootPath][$relativePath] = $payloads;
    }

    public function appendDeletion(Project $project, string $relativePath): void
    {
        unset(
            $this->metadata[$project->rootPath][$relativePath],
            $this->payloads[$project->rootPath][$relativePath],
        );
    }

    /**
     * @param array<string, SourceIndexMetadata>   $metadata
     * @param array<string, array<string, string>> $payloads
     */
    public function replaceProject(string $rootPath, array $metadata, array $payloads): void
    {
        $this->metadata[$rootPath] = $metadata;
        $this->payloads[$rootPath] = $payloads;
    }
}

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class InMemorySourceIndexReader implements SourceIndexReaderInterface
{
    /**
     * @param array<string, SourceIndexMetadata>   $metadata
     * @param array<string, array<string, string>> $payloads
     */
    public function __construct(private readonly array $metadata, private readonly array $payloads)
    {
    }

    public function hasRecords(): bool
    {
        return [] !== $this->metadata;
    }

    public function records(): iterable
    {
        foreach ($this->metadata as $relativePath => $metadata) {
            yield $relativePath => [
                'metadata' => $metadata,
                'payloads' => $this->payloads[$relativePath] ?? [],
            ];
        }
    }

    public function close(): void
    {
    }
}

/**
 * @phpstan-import-type SourceIndexMetadata from SourceIndexStoreInterface
 */
final class InMemorySourceIndexWriter implements SourceIndexWriterInterface
{
    /** @var array<string, SourceIndexMetadata> */
    private array $metadata = [];

    /** @var array<string, array<string, string>> */
    private array $payloads = [];

    public function __construct(private readonly InMemorySourceIndexStore $store, private readonly string $rootPath)
    {
    }

    public function add(string $relativePath, array $metadata, array $payloads): void
    {
        $this->metadata[$relativePath] = $metadata;
        $this->payloads[$relativePath] = $payloads;
    }

    public function commit(): void
    {
        $this->store->replaceProject($this->rootPath, $this->metadata, $this->payloads);
    }

    public function abort(): void
    {
    }
}
