<?php

namespace Symfony\Lsp\Tools\Dogfood;

/**
 * Persists support scores as one JSON line per run and project so the
 * history survives local artifact cleanups and machine changes.
 */
final class SupportLedger
{
    public function __construct(private readonly string $path)
    {
    }

    /** @return list<array<array-key, mixed>> */
    public function entries(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $entries = [];
        foreach (explode("\n", trim((string) file_get_contents($this->path))) as $line) {
            if ('' === $line) {
                continue;
            }
            $entry = json_decode($line, true);
            if (\is_array($entry) && \is_string($entry['run'] ?? null) && \is_string($entry['project'] ?? null)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Appends entries whose run and project are not recorded yet, keeping the
     * file sorted for stable diffs.
     *
     * @param list<array<array-key, mixed>> $entries
     *
     * @return int the number of added entries
     */
    public function record(array $entries): int
    {
        $merged = [];
        foreach ([...$this->entries(), ...$entries] as $entry) {
            if (!\is_string($entry['run'] ?? null) || !\is_string($entry['project'] ?? null)) {
                continue;
            }
            $merged[$entry['run'].'|'.$entry['project']] ??= $entry;
        }
        $added = \count($merged) - \count($this->entries());
        if (0 === $added) {
            return 0;
        }
        ksort($merged, \SORT_STRING);
        $directory = \dirname($this->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }
        $lines = array_map(
            static fn (array $entry): string => json_encode($entry, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
            array_values($merged),
        );
        file_put_contents($this->path, implode("\n", $lines)."\n");

        return $added;
    }
}
