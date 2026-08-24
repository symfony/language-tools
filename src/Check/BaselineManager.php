<?php

namespace Symfony\Lsp\Check;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Project\InvalidConfigurationException;

final class BaselineManager
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly DiagnosticCodeRegistry $diagnosticCodes,
    ) {
    }

    /**
     * @param list<CheckDiagnostic> $diagnostics
     *
     * @return array{diagnostics: list<CheckDiagnostic>, stale: list<BaselineEntry>, path: string|null}
     */
    public function apply(string $workspace, CheckOptions $options, array $diagnostics): array
    {
        if (null === $options->baselinePath) {
            return ['diagnostics' => $diagnostics, 'stale' => [], 'path' => null];
        }

        $path = $this->path($workspace, $options->baselinePath);
        if ('create' === $options->baselineMode && is_file($path)) {
            throw new InvalidConfigurationException(\sprintf('The baseline "%s" already exists; use --refresh-baseline to replace it.', $options->baselinePath));
        }
        if ('refresh' === $options->baselineMode && !is_file($path)) {
            throw new InvalidConfigurationException(\sprintf('The baseline "%s" does not exist; use --generate-baseline to create it.', $options->baselinePath));
        }

        if ('none' === $options->baselineMode) {
            $entries = $this->load($path, $options->baselinePath);
        } else {
            $entries = $this->entries($diagnostics);
            $this->write($path, $entries);
        }

        $remaining = [];
        foreach ($entries as $entry) {
            $remaining[$entry->fingerprint][] = $entry;
        }
        $classified = [];
        foreach ($diagnostics as $diagnostic) {
            $matches = $remaining[$diagnostic->fingerprint] ?? [];
            $entry = array_shift($matches);
            $remaining[$diagnostic->fingerprint] = $matches;
            if (null !== $entry) {
                $classified[] = $diagnostic->withBaselineState('matched');
            } else {
                $classified[] = $diagnostic;
            }
        }

        $stale = [];
        foreach ($remaining as $entriesForFingerprint) {
            array_push($stale, ...$entriesForFingerprint);
        }
        usort($stale, static fn (BaselineEntry $left, BaselineEntry $right): int => [
            $left->project,
            $left->path,
            $left->code,
            $left->message,
            $left->occurrence,
        ] <=> [
            $right->project,
            $right->path,
            $right->code,
            $right->message,
            $right->occurrence,
        ]);

        return [
            'diagnostics' => $classified,
            'stale' => $stale,
            'path' => Path::makeRelative($path, Path::canonicalize($workspace)),
        ];
    }

    /** @return list<BaselineEntry> */
    private function load(string $path, string $displayPath): array
    {
        if (!is_file($path)) {
            throw new InvalidConfigurationException(\sprintf('The baseline "%s" does not exist.', $displayPath));
        }
        if (!is_readable($path)) {
            throw new InvalidConfigurationException(\sprintf('The baseline "%s" is unreadable.', $displayPath));
        }
        try {
            $contents = file_get_contents($path);
            if (false === $contents) {
                throw new \RuntimeException();
            }
            $baseline = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new InvalidConfigurationException(\sprintf('The baseline "%s" is not valid JSON.', $displayPath));
        }
        if (!\is_array($baseline) || 1 !== ($baseline['version'] ?? null) || !\is_array($baseline['diagnostics'] ?? null)) {
            throw new InvalidConfigurationException(\sprintf('The baseline "%s" must use version 1.', $displayPath));
        }

        $entries = [];
        foreach ($baseline['diagnostics'] as $entry) {
            if (!\is_array($entry)
                || !\is_string($entry['project'] ?? null)
                || !$this->relativePath($entry['project'], true)
                || !\is_string($entry['path'] ?? null)
                || !$this->relativePath($entry['path'])
                || !\is_string($entry['code'] ?? null)
                || !$this->diagnosticCodes->contains($entry['code'])
                || !\is_string($entry['severity'] ?? null)
                || !\in_array($entry['severity'], ['error', 'warning', 'information', 'hint'], true)
                || !\is_string($entry['source'] ?? null)
                || '' === $entry['source']
                || !\is_string($entry['message'] ?? null)
                || !\is_string($entry['fingerprint'] ?? null)
                || 1 !== preg_match('/^[a-f0-9]{64}$/D', $entry['fingerprint'])
                || !\is_int($entry['occurrence'] ?? null)
                || $entry['occurrence'] < 1
            ) {
                throw new InvalidConfigurationException(\sprintf('The baseline "%s" contains an invalid diagnostic entry.', $displayPath));
            }
            $entries[] = new BaselineEntry(
                $entry['project'],
                $entry['path'],
                $entry['code'],
                $entry['severity'],
                $entry['source'],
                $entry['message'],
                $entry['fingerprint'],
                $entry['occurrence'],
            );
        }

        $occurrences = [];
        foreach ($entries as $entry) {
            $occurrences[$entry->fingerprint][] = $entry->occurrence;
        }
        foreach ($occurrences as $values) {
            sort($values);
            if ($values !== range(1, \count($values))) {
                throw new InvalidConfigurationException(\sprintf('The baseline "%s" contains invalid diagnostic occurrence numbers.', $displayPath));
            }
        }

        return $entries;
    }

    /**
     * @param list<CheckDiagnostic> $diagnostics
     *
     * @return list<BaselineEntry>
     */
    private function entries(array $diagnostics): array
    {
        $occurrences = [];
        $entries = [];
        foreach ($diagnostics as $diagnostic) {
            $occurrence = ($occurrences[$diagnostic->fingerprint] ?? 0) + 1;
            $occurrences[$diagnostic->fingerprint] = $occurrence;
            $entries[] = new BaselineEntry(
                $diagnostic->project,
                $diagnostic->path,
                $diagnostic->code,
                $diagnostic->severityName(),
                $diagnostic->source,
                $diagnostic->message,
                $diagnostic->fingerprint,
                $occurrence,
            );
        }

        return $entries;
    }

    /** @param list<BaselineEntry> $entries */
    private function write(string $path, array $entries): void
    {
        $json = json_encode([
            'version' => 1,
            'diagnostics' => array_map(static fn (BaselineEntry $entry): array => $entry->toArray(), $entries),
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        $this->filesystem->dumpFile($path, $json."\n");
    }

    private function relativePath(string $path, bool $project = false): bool
    {
        if ($project && '.' === $path) {
            return true;
        }
        if ('' === $path || Path::isAbsolute($path)) {
            return false;
        }
        $path = str_replace('\\', '/', Path::canonicalize($path));

        return '.' !== $path && '..' !== $path && !str_starts_with($path, '../');
    }

    private function path(string $workspace, string $path): string
    {
        $workspace = Path::canonicalize($workspace);
        $path = Path::canonicalize(Path::isAbsolute($path) ? $path : Path::join($workspace, $path));
        if ($workspace !== $path && !Path::isBasePath($workspace, $path)) {
            throw new InvalidConfigurationException('The baseline path must be inside the workspace.');
        }
        $realPath = realpath($path);
        $realWorkspace = realpath($workspace);
        $ancestor = \dirname($path);
        while (!file_exists($ancestor) && $ancestor !== \dirname($ancestor)) {
            $ancestor = \dirname($ancestor);
        }
        $realParent = realpath($ancestor);
        if (false !== $realWorkspace) {
            $realWorkspace = Path::canonicalize($realWorkspace);
            foreach ([$realPath, $realParent] as $resolved) {
                if (false === $resolved) {
                    continue;
                }
                $resolved = Path::canonicalize($resolved);
                if ($realWorkspace !== $resolved && !Path::isBasePath($realWorkspace, $resolved)) {
                    throw new InvalidConfigurationException('The baseline path resolves outside the workspace.');
                }
            }
        }

        return $path;
    }
}
