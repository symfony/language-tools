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
    public function save(Project $project, string $bridge, array $snapshot, array $requestedSections, bool $complete): void
    {
        if (self::SNAPSHOT_SCHEMA_VERSION !== ($snapshot['schemaVersion'] ?? null)) {
            return;
        }

        if ($complete) {
            $previousSnapshot = [];
        } else {
            $previous = $this->load($project, $bridge);
            if (null === $previous) {
                return;
            }
            $previousSnapshot = $previous->snapshot;
        }
        $previousSections = $previousSnapshot['sections'] ?? null;
        $sections = \is_array($previousSections) ? $previousSections : [];
        $incomingSections = \is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [];
        foreach ($requestedSections as $section) {
            if (\is_array($incomingSections[$section] ?? null)) {
                $sections[$section] = $incomingSections[$section];
            } else {
                unset($sections[$section]);
            }
        }

        $this->persist($project, $bridge, $sections, $previousSnapshot);
    }

    /**
     * @param array<array-key, mixed> $snapshot
     * @param list<string>            $availableSections
     */
    public function savePartial(Project $project, string $bridge, array $snapshot, array $availableSections): void
    {
        if (self::SNAPSHOT_SCHEMA_VERSION !== ($snapshot['schemaVersion'] ?? null)) {
            return;
        }

        $previous = $this->load($project, $bridge);
        $previousSnapshot = $previous?->snapshot ?? [];
        $previousSections = $previousSnapshot['sections'] ?? null;
        $sections = \is_array($previousSections) ? $previousSections : [];
        $incomingSections = \is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [];
        foreach ($availableSections as $section) {
            if (\is_array($incomingSections[$section] ?? null)) {
                $sections[$section] = $incomingSections[$section];
            }
        }

        $this->persist($project, $bridge, $sections, $previousSnapshot);
    }

    /** @param array<string, array<array-key, mixed>> $sections */
    private function persist(Project $project, string $bridge, array $sections, array $previousSnapshot): void
    {
        if ([] === $sections) {
            $this->remove($project, $bridge);

            return;
        }

        $payload = [
            'schemaVersion' => self::SNAPSHOT_SCHEMA_VERSION,
            'project' => [
                'root' => $project->rootPath,
                'environment' => $this->configuration->environment($project),
                'debug' => $this->configuration->debug($project),
            ],
            'sections' => $sections,
        ];
        if ($payload === $previousSnapshot) {
            return;
        }

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
        if (3 !== \count($metadata)
            || $project->rootPath !== ($metadata['root'] ?? null)
            || $this->configuration->environment($project) !== ($metadata['environment'] ?? null)
            || $this->configuration->debug($project) !== ($metadata['debug'] ?? null)
        ) {
            return false;
        }

        foreach ($payload['sections'] as $name => $section) {
            if (!\is_string($name) || '' === $name || !\is_array($section)) {
                return false;
            }
        }

        return true;
    }

    private function remove(Project $project, string $bridge): void
    {
        try {
            $this->filesystem->remove($this->path($project, $bridge));
        } catch (IOExceptionInterface) {
        }
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
        $configuration = serialize([
            'projectRoot' => $project->rootPath,
            'phpCommand' => $this->configuration->phpCommand($project),
            'containerProjectRoot' => $this->configuration->containerProjectRoot($project),
            'environment' => $this->configuration->environment($project),
            'debug' => $this->configuration->debug($project),
        ]);

        return Path::join(\dirname($bridge), 'runtime', hash('sha256', $configuration).'.json');
    }
}
