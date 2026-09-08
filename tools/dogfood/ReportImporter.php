<?php

namespace Symfony\Lsp\Tools\Dogfood;

/** @phpstan-import-type HistoryEntry from ReportHistory */
final class ReportImporter
{
    private const MAX_ARTIFACT_BYTES = 25_000_000;
    private const OPERATIONAL_LAYERS = ['provisioning', 'setup', 'bootstrap', 'source-index', 'runtime-index', 'request', 'process', 'timeout'];

    public function __construct(
        private readonly ReportHistory $history = new ReportHistory(),
        private readonly ScenarioEvidence $evidence = new ScenarioEvidence(),
    ) {
    }

    /** @return array{entries: list<HistoryEntry>, legacy: int, warnings: list<string>} */
    public function collect(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException('The matrix artifact directory does not exist.');
        }
        $runs = 1 === preg_match('/^\d{8}-\d{6}$/D', basename($directory)) ? [$directory] : (glob($directory.'/*', \GLOB_ONLYDIR) ?: []);
        sort($runs, \SORT_STRING);
        $entries = [];
        $legacy = 0;
        $warnings = [];
        foreach ($runs as $runDirectory) {
            $run = basename($runDirectory);
            try {
                $time = $this->history->time($run);
            } catch (\UnexpectedValueException) {
                continue;
            }
            foreach (glob($runDirectory.'/*', \GLOB_ONLYDIR) ?: [] as $projectDirectory) {
                $project = basename($projectDirectory);
                if (1 !== preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $project)) {
                    continue;
                }
                $report = null;
                try {
                    $report = $this->read($projectDirectory.'/project.json');
                } catch (\UnexpectedValueException $error) {
                    $warnings[] = $run.'/'.$project.': '.$error->getMessage();
                }
                if (null !== $report && !$this->behavioral($report)) {
                    ++$legacy;
                    continue;
                }
                $phases = [];
                foreach (['cold', 'warm'] as $phase) {
                    try {
                        $phases[$phase] = $this->read($projectDirectory.'/'.$phase.'.json');
                    } catch (\UnexpectedValueException $error) {
                        $phases[$phase] = null;
                        $summary = $this->map($report[$phase] ?? null);
                        $layers = \is_array($summary['layers'] ?? null) ? array_filter($summary['layers'], 'is_string') : [];
                        if ([] === array_intersect($layers, self::OPERATIONAL_LAYERS)) {
                            $warnings[] = $run.'/'.$project.': '.$error->getMessage();
                        }
                    }
                }
                if (null === $report && !isset($phases['cold']['scenarioCount']) && !isset($phases['warm']['scenarioCount'])) {
                    continue;
                }
                try {
                    $entries[] = $this->entry($run, $time, $project, $report, $phases['cold'], $phases['warm']);
                } catch (\UnexpectedValueException $error) {
                    $warnings[] = $run.'/'.$project.': '.$error->getMessage();
                    $entries[] = $this->entry($run, $time, $project, null, null, null);
                }
            }
        }

        return ['entries' => $entries, 'legacy' => $legacy, 'warnings' => $warnings];
    }

    /** @param array<string, mixed> $report */
    private function behavioral(array $report): bool
    {
        $cold = $this->map($report['cold'] ?? null);
        $warm = $this->map($report['warm'] ?? null);

        return \array_key_exists('expectationFingerprint', $report) || \array_key_exists('knownGaps', $report) || isset($cold['scenarios']) || isset($warm['scenarios']);
    }

    /**
     * @param array<string, mixed>|null $report
     * @param array<string, mixed>|null $cold
     * @param array<string, mixed>|null $warm
     *
     * @return HistoryEntry
     */
    private function entry(string $run, string $time, string $project, ?array $report, ?array $cold, ?array $warm): array
    {
        if (null !== $report && ($report['name'] ?? null) !== $project) {
            throw new \UnexpectedValueException('Project identity differs from its directory.');
        }
        $summaryCold = $this->map($report['cold'] ?? null);
        $summaryWarm = $this->map($report['warm'] ?? null);
        $details = [];
        $statuses = ['pass' => 0, 'fail' => 0, 'error' => 0];
        $available = 0;
        foreach (['cold' => $cold, 'warm' => $warm] as $phase => $response) {
            if (null === $response) {
                continue;
            }
            $scenarios = $response['scenarios'] ?? null;
            if (!\is_array($scenarios) || !array_is_list($scenarios) || ($response['scenarioCount'] ?? null) !== \count($scenarios)) {
                throw new \UnexpectedValueException('Malformed behavioral response.');
            }
            ++$available;
            $count = 0;
            foreach ($scenarios as $scenario) {
                if (!\is_array($scenario) || !\is_string($scenario['id'] ?? null) || !\is_array($scenario['checks'] ?? null)) {
                    throw new \UnexpectedValueException('Malformed scenario checks.');
                }
                foreach ($scenario['checks'] as $check) {
                    if (!\is_array($check) || !\is_string($check['status'] ?? null) || !isset($statuses[$check['status']])
                        || !\is_string($check['phase'] ?? null) || !\is_string($check['method'] ?? null)
                    ) {
                        throw new \UnexpectedValueException('Malformed check outcome.');
                    }
                    if (true === ($report['ok'] ?? null) && ('pass' !== ($scenario['status'] ?? null) || 'pass' !== $check['status'])) {
                        throw new \UnexpectedValueException('Passing result contains unsuccessful checks.');
                    }
                    ++$statuses[$check['status']];
                    ++$count;
                    $details[] = [$phase, $scenario['id'], $check['phase'], $check['method']];
                }
            }
            $summary = 'cold' === $phase ? $summaryCold : $summaryWarm;
            if (isset($summary['checks']) && $summary['checks'] !== $count) {
                throw new \UnexpectedValueException('Summary and detailed check counts disagree.');
            }
        }
        $layers = [];
        foreach ([$summaryCold, $summaryWarm] as $summary) {
            foreach (\is_array($summary['layers'] ?? null) ? $summary['layers'] : [] as $layer) {
                if (!\in_array($layer, ReportHistory::LAYERS, true)) {
                    throw new \UnexpectedValueException('Unknown failure layer.');
                }
                $layers[] = $layer;
            }
        }
        $failure = $this->map($report['failure'] ?? null);
        if (isset($failure['layer'])) {
            if (!\in_array($failure['layer'], ReportHistory::LAYERS, true)) {
                throw new \UnexpectedValueException('Unknown project failure layer.');
            }
            $layers[] = $failure['layer'];
        }
        $diagnosticReport = $this->map($report['diagnostics'] ?? null);
        $diagnostics = null;
        $knownGaps = null;
        $files = null;
        if (true === ($diagnosticReport['ok'] ?? null)) {
            $items = $diagnosticReport['diagnostics'] ?? null;
            if (!\is_array($items) || !array_is_list($items)) {
                throw new \UnexpectedValueException('Malformed diagnostic observation.');
            }
            $known = $report['knownGaps'] ?? [];
            if (!\is_array($known) || !array_is_list($known)) {
                throw new \UnexpectedValueException('Malformed known-gap observation.');
            }
            foreach ([...$items, ...$known] as $diagnostic) {
                if (!\is_array($diagnostic)) {
                    throw new \UnexpectedValueException('Malformed diagnostic entry.');
                }
            }
            /** @var list<array<string, mixed>> $items */
            /** @var list<array<string, mixed>> $known */
            $remaining = array_count_values($this->evidence->diagnostics($items));
            $knownGaps = 0;
            foreach ($this->evidence->diagnostics($known) as $item) {
                if (0 < ($remaining[$item] ?? 0)) {
                    ++$knownGaps;
                    --$remaining[$item];
                }
            }
            $diagnostics = \count($items);
            $files = $this->integer($diagnosticReport['analyzedFiles'] ?? null);
        }
        $layers = array_values(array_unique($layers));
        sort($layers, \SORT_STRING);
        $outcome = 'incomplete';
        if (null !== $report) {
            if (true === ($report['ok'] ?? null)) {
                if (2 !== $available || [] !== $layers || 0 !== $statuses['fail'] || 0 !== $statuses['error'] || null === $files || 0 === $files
                    || 'ready' !== ($summaryCold['source'] ?? null) || 'ready' !== ($summaryWarm['source'] ?? null)
                    || 'ready' !== ($summaryCold['runtime'] ?? null) || 'ready' !== ($summaryWarm['runtime'] ?? null)
                ) {
                    throw new \UnexpectedValueException('Passing result lacks complete evidence.');
                }
                $outcome = 'passed';
            } elseif ([] !== array_intersect($layers, self::OPERATIONAL_LAYERS) || false === ($diagnosticReport['ok'] ?? null)) {
                $outcome = 'blocked';
            } elseif ([] !== $layers || 0 < $statuses['fail']) {
                $outcome = 'failed';
            }
        }
        if ('incomplete' === $outcome) {
            $layers[] = 'artifact';
        }
        $revision = $this->digest($report['revision'] ?? $warm['manifestRevision'] ?? $cold['manifestRevision'] ?? null, 40);
        $dependencies = $this->digest($this->map($report['dependencies'] ?? null)['composerLockSha256'] ?? null);
        $environment = $this->label($report['environment'] ?? $warm['environment'] ?? $cold['environment'] ?? null);
        $expectations = $this->digest($report['expectationFingerprint'] ?? null);
        sort($details);
        $entry = [
            'version' => 1,
            'run' => $run,
            'project' => $project,
            'time' => $time,
            'outcome' => $outcome,
            'finalized' => null !== $report && 'incomplete' !== $outcome,
            'layers' => array_values(array_unique($layers)),
            'revision' => $revision,
            'dependencies' => $dependencies,
            'environment' => $environment,
            'framework' => $this->label($report['frameworkBundle'] ?? null),
            'serverVersion' => $this->label($summaryWarm['serverVersion'] ?? $summaryCold['serverVersion'] ?? $warm['serverVersion'] ?? $cold['serverVersion'] ?? null),
            'expectations' => $expectations,
            'checkSet' => 2 === $available ? hash('sha256', json_encode($details, \JSON_THROW_ON_ERROR)) : null,
            'comparison' => $this->history->comparison($revision, $dependencies, $environment, $expectations),
            'scenarios' => $this->integer($summaryWarm['scenarios'] ?? $summaryCold['scenarios'] ?? $warm['scenarioCount'] ?? $cold['scenarioCount'] ?? null),
            'checks' => 0 === $available ? null : array_sum($statuses),
            'passed' => 0 === $available ? null : $statuses['pass'],
            'failed' => 0 === $available ? null : $statuses['fail'],
            'errors' => 0 === $available ? null : $statuses['error'],
            'requests' => $this->sum($summaryCold['requests'] ?? $cold['requestCount'] ?? null, $summaryWarm['requests'] ?? $warm['requestCount'] ?? null),
            'knownGaps' => $knownGaps,
            'diagnostics' => $diagnostics,
            'files' => $files,
            'milliseconds' => $this->duration($this->map($report['timings'] ?? null)['totalMilliseconds'] ?? null),
            'scenarioMilliseconds' => $this->sumDurations($this->map($summaryCold['timings'] ?? null)['scenariosMilliseconds'] ?? null, $this->map($summaryWarm['timings'] ?? null)['scenariosMilliseconds'] ?? null),
        ];

        return $this->history->validate($entry);
    }

    /** @return array<string, mixed>|null */
    private function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path, length: self::MAX_ARTIFACT_BYTES + 1);
        if (false === $contents || \strlen($contents) > self::MAX_ARTIFACT_BYTES) {
            throw new \UnexpectedValueException('Artifact is unreadable or exceeds the size limit.');
        }
        try {
            $value = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \UnexpectedValueException('Artifact is not valid JSON.');
        }
        if (!\is_array($value) || [] === $value || array_is_list($value)) {
            throw new \UnexpectedValueException('Artifact must be an object.');
        }
        $fields = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('Artifact must have named fields.');
            }
            $fields[$key] = $item;
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $fields = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                return [];
            }
            $fields[$key] = $item;
        }

        return $fields;
    }

    private function integer(mixed $value): ?int
    {
        if (null !== $value && (!\is_int($value) || $value < 0)) {
            throw new \UnexpectedValueException('Invalid artifact count.');
        }

        return $value;
    }

    private function duration(mixed $value): ?float
    {
        if (null === $value) {
            return null;
        }
        if ((!\is_int($value) && !\is_float($value)) || !is_finite((float) $value) || $value < 0) {
            throw new \UnexpectedValueException('Invalid artifact duration.');
        }

        return (float) $value;
    }

    private function sum(mixed $left, mixed $right): ?int
    {
        $left = $this->integer($left);
        $right = $this->integer($right);

        return null === $left || null === $right ? null : $left + $right;
    }

    private function sumDurations(mixed $left, mixed $right): ?float
    {
        $left = $this->duration($left);
        $right = $this->duration($right);

        return null === $left || null === $right ? null : $left + $right;
    }

    private function digest(mixed $value, int $length = 64): ?string
    {
        if (null !== $value && (!\is_string($value) || 1 !== preg_match('/^[a-f0-9]{'.$length.'}$/D', $value))) {
            throw new \UnexpectedValueException('Invalid artifact fingerprint.');
        }

        return $value;
    }

    private function label(mixed $value): ?string
    {
        if (null !== $value && (!\is_string($value) || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9._+\-]{0,99}$/D', $value))) {
            throw new \UnexpectedValueException('Invalid artifact version or environment.');
        }

        return $value;
    }
}
