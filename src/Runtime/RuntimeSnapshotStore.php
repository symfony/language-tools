<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Project\Project;

final class RuntimeSnapshotStore
{
    private const FORMAT_VERSION = 1;
    private const SNAPSHOT_SCHEMA_VERSION = 1;
    private const JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES;
    private const OPTIONAL_PROJECT_METADATA = [
        'symfonyVersion',
        'symfonyBranch',
        'phpVersion',
    ];

    public function __construct(
        private readonly RuntimeConfiguration $configuration,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function load(Project $project, string $bridge): ?RuntimeSnapshot
    {
        $contents = @file_get_contents($this->path($project, $bridge));
        if (false === $contents) {
            return null;
        }

        try {
            $envelope = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($envelope)) {
            return null;
        }
        $lastSuccessfulAt = $envelope['lastSuccessfulAt'] ?? null;
        $payloadHash = $envelope['payloadHash'] ?? null;
        $payload = $envelope['payload'] ?? null;
        if (4 !== \count($envelope)
            || self::FORMAT_VERSION !== ($envelope['formatVersion'] ?? null)
            || !\is_string($lastSuccessfulAt)
            || !$this->validTimestamp($lastSuccessfulAt)
            || !\is_string($payloadHash)
            || 1 !== preg_match('/^[a-f0-9]{64}$/D', $payloadHash)
            || !\is_array($payload)
        ) {
            return null;
        }

        try {
            $payloadJson = json_encode($payload, self::JSON_FLAGS);
        } catch (\JsonException) {
            return null;
        }
        if (!hash_equals($payloadHash, hash('sha256', $payloadJson))
            || !$this->validPayload($project, $payload)
        ) {
            return null;
        }

        return new RuntimeSnapshot($payload, $lastSuccessfulAt);
    }

    /**
     * @param array<array-key, mixed> $snapshot
     * @param list<string>            $requestedSections
     */
    public function save(Project $project, string $bridge, array $snapshot, array $requestedSections): void
    {
        if (self::SNAPSHOT_SCHEMA_VERSION !== ($snapshot['schemaVersion'] ?? null)) {
            return;
        }

        $previous = $this->load($project, $bridge)?->snapshot;
        $sections = \is_array($previous['sections'] ?? null) ? $previous['sections'] : [];
        $incomingSections = \is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [];
        foreach ($requestedSections as $section) {
            if (\is_array($incomingSections[$section] ?? null)) {
                $sections[$section] = $incomingSections[$section];
            } else {
                unset($sections[$section]);
            }
        }

        $payload = [
            'schemaVersion' => self::SNAPSHOT_SCHEMA_VERSION,
            'project' => $this->projectMetadata($project, $snapshot, $previous),
            'sections' => $sections,
        ];

        try {
            $payloadJson = json_encode($payload, self::JSON_FLAGS);
            $envelope = json_encode([
                'formatVersion' => self::FORMAT_VERSION,
                'lastSuccessfulAt' => gmdate(\DateTimeInterface::ATOM),
                'payloadHash' => hash('sha256', $payloadJson),
                'payload' => $payload,
            ], self::JSON_FLAGS);
            $path = $this->path($project, $bridge);
            $this->filesystem->mkdir(\dirname($path));
            $this->filesystem->dumpFile($path, $envelope."\n");
        } catch (IOExceptionInterface|\JsonException) {
        }
    }

    /**
     * @param array<array-key, mixed>  $snapshot
     * @param ?array<array-key, mixed> $previous
     *
     * @return array<string, mixed>
     */
    private function projectMetadata(Project $project, array $snapshot, ?array $previous): array
    {
        $metadata = [
            'root' => $project->rootPath(),
            'environment' => $this->configuration->environment($project),
            'debug' => $this->configuration->debug($project),
        ];
        $incomingProject = \is_array($snapshot['project'] ?? null) ? $snapshot['project'] : [];
        $previousProject = \is_array($previous['project'] ?? null) ? $previous['project'] : [];
        foreach (self::OPTIONAL_PROJECT_METADATA as $name) {
            $value = $incomingProject[$name] ?? $previousProject[$name] ?? null;
            if (\is_string($value)) {
                $metadata[$name] = $value;
            }
        }

        return $metadata;
    }

    /** @param array<array-key, mixed> $payload */
    private function validPayload(Project $project, array $payload): bool
    {
        if (3 !== \count($payload)
            || self::SNAPSHOT_SCHEMA_VERSION !== ($payload['schemaVersion'] ?? null)
            || !\is_array($payload['project'] ?? null)
            || !\is_array($payload['sections'] ?? null)
            || [] === $payload['sections']
        ) {
            return false;
        }

        $metadata = $payload['project'];
        if ($project->rootPath() !== ($metadata['root'] ?? null)
            || $this->configuration->environment($project) !== ($metadata['environment'] ?? null)
            || $this->configuration->debug($project) !== ($metadata['debug'] ?? null)
            || \count($metadata) > 6
        ) {
            return false;
        }
        foreach ($metadata as $name => $value) {
            if (!\is_string($name)
                || (!\in_array($name, self::OPTIONAL_PROJECT_METADATA, true) && !\in_array($name, ['root', 'environment', 'debug'], true))
                || (\in_array($name, self::OPTIONAL_PROJECT_METADATA, true) && !\is_string($value))
            ) {
                return false;
            }
        }

        foreach ($payload['sections'] as $name => $section) {
            if (!\is_string($name) || '' === $name || !\is_array($section)) {
                return false;
            }
        }

        return true;
    }

    private function validTimestamp(mixed $timestamp): bool
    {
        if (!\is_string($timestamp)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);

        return false !== $date
            && 0 === $date->getOffset()
            && $timestamp === $date->format(\DateTimeInterface::ATOM);
    }

    private function path(Project $project, string $bridge): string
    {
        $configuration = json_encode([
            'projectRoot' => $project->rootPath(),
            'phpCommand' => $this->configuration->phpCommand($project),
            'containerProjectRoot' => $this->configuration->containerProjectRoot($project),
            'environment' => $this->configuration->environment($project),
            'debug' => $this->configuration->debug($project),
        ], self::JSON_FLAGS);

        return Path::join(\dirname($bridge), 'runtime', hash('sha256', $configuration).'.json');
    }
}
