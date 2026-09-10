<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Component\Filesystem\Filesystem;

/**
 * @phpstan-type HistoryEntry array{version: 2, run: string, project: string, time: string, outcome: string, finalized: bool, layers: list<string>, analysisMode: ?string, revision: ?string, dependencies: ?string, environment: ?string, framework: ?string, serverVersion: ?string, expectations: ?string, checkSet: ?string, comparison: ?string, scenarios: ?int, checks: ?int, passed: ?int, failed: ?int, errors: ?int, requests: ?int, knownGaps: ?int, diagnostics: ?int, files: ?int, milliseconds: ?float, scenarioMilliseconds: ?float}
 */
final class ReportHistory
{
    public const LAYERS = ['analysis-mode', 'provisioning', 'setup', 'bootstrap', 'source-index', 'runtime-index', 'request', 'process', 'timeout', 'scenario', 'cache-parity', 'diagnostics', 'budget', 'artifact'];

    private const COUNTS = ['scenarios', 'checks', 'passed', 'failed', 'errors', 'requests', 'knownGaps', 'diagnostics', 'files'];
    private const DURATIONS = ['milliseconds', 'scenarioMilliseconds'];
    private const HASHES = ['dependencies', 'expectations', 'checkSet', 'comparison'];
    private const LABELS = ['environment', 'framework', 'serverVersion'];
    private const KEYS = ['version', 'run', 'project', 'time', 'outcome', 'finalized', 'layers', 'analysisMode', 'revision', ...self::HASHES, ...self::LABELS, ...self::COUNTS, ...self::DURATIONS];

    public function __construct(private readonly Filesystem $filesystem = new Filesystem())
    {
    }

    /** @return list<HistoryEntry> */
    public function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException('Unable to read the dogfood history ledger.');
        }
        $entries = [];
        foreach (explode("\n", rtrim($contents, "\n")) as $line => $json) {
            if ('' === trim($json)) {
                continue;
            }
            try {
                $entries[] = $this->validate(json_decode($json, true, flags: \JSON_THROW_ON_ERROR));
            } catch (\JsonException|\UnexpectedValueException $error) {
                throw new \RuntimeException(\sprintf('Invalid dogfood history entry on line %d: %s', $line + 1, $error->getMessage()), 0, $error);
            }
        }

        return $this->merge([], $entries)['entries'];
    }

    /** @param list<HistoryEntry> $entries */
    public function save(string $path, array $entries): void
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = json_encode($this->validate($entry), \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        }
        $contents = [] === $lines ? '' : implode("\n", $lines)."\n";
        if (is_file($path) && $contents === file_get_contents($path)) {
            return;
        }
        $this->filesystem->dumpFile($path, $contents);
    }

    /**
     * @param list<HistoryEntry> $existing
     * @param list<HistoryEntry> $incoming
     *
     * @return array{entries: list<HistoryEntry>, added: int, updated: int}
     */
    public function merge(array $existing, array $incoming): array
    {
        $entries = [];
        foreach ($existing as $entry) {
            $entry = $this->validate($entry);
            $key = $entry['run'].'/'.$entry['project'];
            if (isset($entries[$key])) {
                throw new \UnexpectedValueException('The history ledger contains a duplicate run/project identity.');
            }
            $entries[$key] = $entry;
        }
        $added = 0;
        $updated = 0;
        foreach ($incoming as $entry) {
            $entry = $this->validate($entry);
            $key = $entry['run'].'/'.$entry['project'];
            $previous = $entries[$key] ?? null;
            if (null === $previous) {
                $entries[$key] = $entry;
                ++$added;
                continue;
            }
            if ($previous === $entry || (!$entry['finalized'] && $previous['finalized'])) {
                continue;
            }
            if ($previous['finalized']) {
                throw new \UnexpectedValueException(\sprintf('Recorded results for %s changed; refusing to overwrite finalized history.', $key));
            }
            foreach (['analysisMode', 'revision', 'dependencies', 'environment', 'expectations'] as $field) {
                if (null !== $previous[$field] && null !== $entry[$field] && $previous[$field] !== $entry[$field]) {
                    throw new \UnexpectedValueException(\sprintf('The input identity for incomplete run %s changed.', $key));
                }
            }
            if ($entry['finalized'] || ($entry['checks'] ?? -1) > ($previous['checks'] ?? -1)) {
                $entries[$key] = $entry;
                ++$updated;
            }
        }
        ksort($entries, \SORT_STRING);

        return ['entries' => array_values($entries), 'added' => $added, 'updated' => $updated];
    }

    /** @return HistoryEntry */
    public function validate(mixed $entry): array
    {
        $legacy = \is_array($entry) && 1 === ($entry['version'] ?? null) && !\array_key_exists('analysisMode', $entry);
        if ($legacy) {
            $entry['version'] = 2;
            $entry['analysisMode'] = 'runtime';
        }
        if (!\is_array($entry) || 2 !== ($entry['version'] ?? null)
            || [] !== array_diff(self::KEYS, array_keys($entry))
            || [] !== array_diff(array_keys($entry), self::KEYS)
        ) {
            throw new \UnexpectedValueException('Unsupported history entry shape.');
        }
        if (!\is_string($entry['run']) || !\is_string($entry['time']) || $this->time($entry['run']) !== $entry['time']
            || !\is_string($entry['project']) || 1 !== preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $entry['project'])
            || !\in_array($entry['outcome'], ['passed', 'failed', 'blocked', 'incomplete'], true)
            || !\in_array($entry['analysisMode'], [null, 'runtime', 'source-only'], true)
            || !\is_bool($entry['finalized']) || !\is_array($entry['layers']) || !array_is_list($entry['layers'])
        ) {
            throw new \UnexpectedValueException('Invalid history identity or outcome.');
        }
        foreach ($entry['layers'] as $layer) {
            if (!\in_array($layer, self::LAYERS, true)) {
                throw new \UnexpectedValueException('Unknown history failure layer.');
            }
        }
        foreach (['revision' => 40, ...array_fill_keys(self::HASHES, 64)] as $key => $length) {
            if (null !== $entry[$key] && (!\is_string($entry[$key]) || 1 !== preg_match('/^[a-f0-9]{'.$length.'}$/D', $entry[$key]))) {
                throw new \UnexpectedValueException('Invalid history fingerprint.');
            }
        }
        foreach (self::LABELS as $key) {
            $pattern = 'environment' === $key ? '/^[A-Za-z0-9_][A-Za-z0-9._+\-]{0,99}$/D' : '/^[A-Za-z0-9][A-Za-z0-9._+\/\-]{0,99}$/D';
            if (null !== $entry[$key] && (!\is_string($entry[$key]) || 1 !== preg_match($pattern, $entry[$key]))) {
                throw new \UnexpectedValueException('Invalid history version or environment.');
            }
        }
        foreach (self::COUNTS as $key) {
            if (null !== $entry[$key] && (!\is_int($entry[$key]) || $entry[$key] < 0)) {
                throw new \UnexpectedValueException('Invalid history count.');
            }
        }
        foreach (self::DURATIONS as $key) {
            if (null !== $entry[$key]) {
                if ((!\is_int($entry[$key]) && !\is_float($entry[$key])) || !is_finite((float) $entry[$key]) || $entry[$key] < 0) {
                    throw new \UnexpectedValueException('Invalid history duration.');
                }
                $entry[$key] = (float) $entry[$key];
            }
        }
        if (null === $entry['checks'] && (null !== $entry['passed'] || null !== $entry['failed'] || null !== $entry['errors'])) {
            throw new \UnexpectedValueException('History check totals disagree.');
        }
        if (null !== $entry['checks'] && (null === $entry['passed'] || null === $entry['failed'] || null === $entry['errors'] || $entry['passed'] + $entry['failed'] + $entry['errors'] !== $entry['checks'])) {
            throw new \UnexpectedValueException('History check totals disagree.');
        }
        if ('passed' === $entry['outcome'] && (null === $entry['analysisMode'] || !$entry['finalized'] || [] !== $entry['layers'] || null === $entry['checks'] || $entry['checks'] < 1 || 0 !== $entry['failed'] || 0 !== $entry['errors'] || null === $entry['files'] || $entry['files'] < 1 || null === $entry['diagnostics'])) {
            throw new \UnexpectedValueException('A passing history entry has incomplete evidence.');
        }
        if (null !== $entry['knownGaps'] && null !== $entry['diagnostics'] && $entry['knownGaps'] > $entry['diagnostics']) {
            throw new \UnexpectedValueException('History gap totals disagree.');
        }
        $comparison = $this->comparison($entry['revision'], $entry['dependencies'], $entry['environment'], $entry['expectations'], $entry['analysisMode']);
        if ($legacy && null !== $comparison) {
            $legacyComparison = hash('sha256', json_encode([$entry['revision'], $entry['dependencies'], $entry['environment'], $entry['expectations']], \JSON_THROW_ON_ERROR));
            if ($entry['comparison'] !== $legacyComparison) {
                throw new \UnexpectedValueException('History comparison fingerprint disagrees with its inputs.');
            }
            $entry['comparison'] = $comparison;
        }
        if ($entry['comparison'] !== $comparison) {
            throw new \UnexpectedValueException('History comparison fingerprint disagrees with its inputs.');
        }

        $entry = array_replace(array_fill_keys(self::KEYS, null), $entry);

        /* @var HistoryEntry $entry */
        return $entry;
    }

    public function time(string $run): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Ymd-His', $run, new \DateTimeZone('UTC'));
        if (false === $date || $run !== $date->format('Ymd-His')) {
            throw new \UnexpectedValueException('A run must have a valid UTC timestamp directory name.');
        }

        return $date->format('Y-m-d\TH:i:s\Z');
    }

    public function comparison(?string $revision, ?string $dependencies, ?string $environment, ?string $expectations, ?string $analysisMode = 'runtime'): ?string
    {
        if (null === $revision || null === $dependencies || null === $environment || null === $expectations || null === $analysisMode) {
            return null;
        }

        return hash('sha256', json_encode([$revision, $dependencies, $environment, $expectations, $analysisMode], \JSON_THROW_ON_ERROR));
    }
}
