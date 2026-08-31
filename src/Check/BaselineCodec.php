<?php

namespace Symfony\Lsp\Check;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Feature\DiagnosticCodeRegistry;
use Symfony\Lsp\Project\InvalidConfigurationException;

final class BaselineCodec
{
    public function __construct(private readonly DiagnosticCodeRegistry $diagnosticCodes)
    {
    }

    /** @return list<BaselineEntry> */
    public function decode(string $contents, string $displayPath): array
    {
        try {
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

    /** @param list<BaselineEntry> $entries */
    public function encode(array $entries): string
    {
        return json_encode([
            'version' => 1,
            'diagnostics' => array_map(static fn (BaselineEntry $entry): array => $entry->toArray(), $entries),
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)."\n";
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
}
