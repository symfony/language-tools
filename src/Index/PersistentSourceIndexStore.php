<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Project\Project;

final class PersistentSourceIndexStore implements SourceIndexStoreInterface
{
    private const SCHEMA_VERSION = 2;

    public function __construct(private readonly string $serverVersion)
    {
    }

    public function load(Project $project): array
    {
        $path = $this->path($project);
        if (!is_file($path)) {
            return [];
        }

        try {
            $contents = file_get_contents($path);
            if (false === $contents) {
                return [];
            }
            $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($data)
            || self::SCHEMA_VERSION !== ($data['schemaVersion'] ?? null)
            || $this->serverVersion !== ($data['serverVersion'] ?? null)
            || !\is_array($data['entries'] ?? null)
        ) {
            return [];
        }

        $entries = [];
        foreach ($data['entries'] as $relativePath => $entry) {
            if (!\is_string($relativePath) || !$this->validEntry($entry)) {
                continue;
            }
            $entries[$relativePath] = $entry;
        }

        return $entries;
    }

    public function save(Project $project, array $entries): void
    {
        $path = $this->path($project);
        $directory = \dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return;
        }

        $json = json_encode([
            'schemaVersion' => self::SCHEMA_VERSION,
            'serverVersion' => $this->serverVersion,
            'entries' => $entries,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $temporaryPath = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (false === @file_put_contents($temporaryPath, $json, \LOCK_EX)) {
            return;
        }
        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
        }
    }

    private function path(Project $project): string
    {
        return $project->rootPath().'/var/symfony-lsp/'.$this->serverVersion.'/index/source.json';
    }

    /**
     * @phpstan-assert-if-true array{size: int, modifiedAt: int, hash: string, languageId: string, providers: array<string, string>} $entry
     */
    private function validEntry(mixed $entry): bool
    {
        if (!\is_array($entry)
            || !\is_int($entry['size'] ?? null)
            || !\is_int($entry['modifiedAt'] ?? null)
            || !\is_string($entry['hash'] ?? null)
            || 64 !== \strlen($entry['hash'])
            || !\is_string($entry['languageId'] ?? null)
            || !\is_array($entry['providers'] ?? null)
        ) {
            return false;
        }

        foreach ($entry['providers'] as $name => $payload) {
            if (!\is_string($name) || !\is_string($payload)) {
                return false;
            }
        }

        return true;
    }
}
