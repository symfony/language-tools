<?php

namespace Symfony\Lsp\Tools\Dogfood;

/**
 * Renders recorded dogfood observations as one offline HTML page.
 *
 * The page answers "what happened to this project over time", never "how
 * supported is Symfony LSP": observations are kept per project because the
 * matrix membership changes between runs, so any cross project total would read
 * as progress nobody measured. Unmeasured values stay visible as unknown
 * instead of collapsing to zero, blocked and incomplete runs never look like
 * passing ones, and two observations are only compared when they carry the same
 * non-null comparison fingerprint.
 *
 * Everything is escaped and rendered server side: the page embeds no data in
 * scripts, loads no font, stylesheet, or chart library, and contains no
 * rendering timestamp so identical observations always produce identical bytes.
 *
 * @phpstan-type Observation array{run: string, project: string, time: string|null, outcome: string, finalized: bool, layers: list<string>, analysisMode: string|null, revision: string|null, dependencies: string|null, environment: string|null, framework: string|null, serverVersion: string|null, expectations: string|null, checkSet: string|null, comparison: string|null, scenarios: int|null, checks: int|null, passed: int|null, failed: int|null, errors: int|null, requests: int|null, knownGaps: int|null, diagnostics: int|null, files: int|null, milliseconds: float|null, scenarioMilliseconds: float|null}
 * @phpstan-type ProjectView array{name: string, slug: string, observations: list<Observation>, comparable: list<bool>}
 * @phpstan-type Series array{label: string, values: list<float|null>}
 */
final class HistoryHtmlReport
{
    private const OUTCOMES = [
        'passed' => 'Passed',
        'failed' => 'Failed',
        'blocked' => 'Blocked',
        'incomplete' => 'Incomplete',
    ];

    private const STRIP_LENGTH = 24;

    private const WIDTH = 360.0;
    private const HEIGHT = 150.0;
    private const LEFT = 52.0;
    private const RIGHT = 10.0;
    private const TOP = 10.0;
    private const BOTTOM = 26.0;

    /**
     * @param list<array<string, mixed>> $entries flat observations, oldest first
     */
    public function render(array $entries): string
    {
        $projects = $this->projects($entries);
        if ([] === $projects) {
            return $this->page('<p class="empty">No dogfood observations recorded yet.</p>');
        }

        return $this->page($this->intro($projects).$this->overview($projects).$this->selector($projects).implode('', array_map($this->panel(...), $projects)));
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<ProjectView>
     */
    private function projects(array $entries): array
    {
        /** @var array<string, list<Observation>> $grouped */
        $grouped = [];
        foreach ($entries as $entry) {
            $observation = $this->observation($entry);
            $grouped[$observation['project']][] = $observation;
        }
        ksort($grouped, \SORT_STRING);

        $projects = [];
        foreach (array_keys($grouped) as $index => $name) {
            $slug = trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name)), '-');
            $projects[] = [
                'name' => $name,
                'slug' => 'project-'.('' === $slug ? 'item' : $slug).'-'.$index,
                'observations' => $grouped[$name],
                'comparable' => $this->comparability($grouped[$name]),
            ];
        }

        return $projects;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return Observation
     */
    private function observation(array $entry): array
    {
        return [
            'run' => $this->text($entry['run'] ?? null) ?? 'unknown run',
            'project' => $this->text($entry['project'] ?? null) ?? 'unknown project',
            'time' => $this->text($entry['time'] ?? null),
            'outcome' => $this->text($entry['outcome'] ?? null) ?? '',
            'finalized' => true === ($entry['finalized'] ?? null),
            'layers' => $this->textList($entry['layers'] ?? null),
            'analysisMode' => \in_array($entry['analysisMode'] ?? null, ['runtime', 'source-only'], true) ? $entry['analysisMode'] : null,
            'revision' => $this->text($entry['revision'] ?? null),
            'dependencies' => $this->text($entry['dependencies'] ?? null),
            'environment' => $this->text($entry['environment'] ?? null),
            'framework' => $this->text($entry['framework'] ?? null),
            'serverVersion' => $this->text($entry['serverVersion'] ?? null),
            'expectations' => $this->text($entry['expectations'] ?? null),
            'checkSet' => $this->text($entry['checkSet'] ?? null),
            'comparison' => $this->text($entry['comparison'] ?? null),
            'scenarios' => $this->count($entry['scenarios'] ?? null),
            'checks' => $this->count($entry['checks'] ?? null),
            'passed' => $this->count($entry['passed'] ?? null),
            'failed' => $this->count($entry['failed'] ?? null),
            'errors' => $this->count($entry['errors'] ?? null),
            'requests' => $this->count($entry['requests'] ?? null),
            'knownGaps' => $this->count($entry['knownGaps'] ?? null),
            'diagnostics' => $this->count($entry['diagnostics'] ?? null),
            'files' => $this->count($entry['files'] ?? null),
            'milliseconds' => $this->number($entry['milliseconds'] ?? null),
            'scenarioMilliseconds' => $this->number($entry['scenarioMilliseconds'] ?? null),
        ];
    }

    /**
     * @param list<Observation> $observations
     *
     * @return list<bool> whether each observation may be compared with the one before it
     */
    private function comparability(array $observations): array
    {
        $flags = [];
        foreach ($observations as $index => $observation) {
            $previous = 0 === $index ? null : $observations[$index - 1];
            $flags[] = null !== $previous && $previous['finalized'] && $observation['finalized']
                && 'incomplete' !== $previous['outcome'] && 'incomplete' !== $observation['outcome']
                && null !== $observation['analysisMode'] && $previous['analysisMode'] === $observation['analysisMode']
                && null !== $previous['comparison'] && $previous['comparison'] === $observation['comparison'];
        }

        return $flags;
    }

    /**
     * @param list<ProjectView> $projects
     */
    private function intro(array $projects): string
    {
        $runs = [];
        $observations = 0;
        foreach ($projects as $project) {
            $observations += \count($project['observations']);
            foreach ($project['observations'] as $observation) {
                $runs[$observation['run']] = true;
            }
        }
        $identifiers = array_keys($runs);
        sort($identifiers, \SORT_STRING);
        $span = \sprintf('%s to %s', $identifiers[0], $identifiers[\count($identifiers) - 1]);

        $legend = '';
        foreach (self::OUTCOMES as $outcome => $label) {
            $legend .= \sprintf('<span class="badge outcome-%s">%s</span> ', $outcome, $label);
        }

        return \sprintf(
            '<p class="summary">%s, %s, %s, %s.</p>'
            .'<p class="note">Every number belongs to a single project: the matrix membership changes between runs, so nothing is averaged into a global score. '
            .'Missing measurements read as unknown, never as zero. Known gaps are confirmed analyzer limitations, separate from new failures. '
            .'Source-only observations do not boot the application or validate runtime metadata. '
            .'Two observations are only compared when they share the same analysis mode and non-null comparison fingerprint.</p>'
            .'<p class="note">%sPassed and failed describe verified assertions, blocked means setup or process trouble, incomplete means the run did not finish or its artifacts are incomplete.</p>',
            $this->plural($observations, 'observation'),
            $this->plural(\count($projects), 'project'),
            $this->plural(\count($identifiers), 'run'),
            $this->escape($span),
            $legend,
        );
    }

    /**
     * @param list<ProjectView> $projects
     */
    private function overview(array $projects): string
    {
        $rows = '';
        foreach ($projects as $project) {
            $latest = $project['observations'][\count($project['observations']) - 1];
            $rows .= \sprintf(
                '<tr><th scope="row"><a href="#%s">%s</a></th><td>%d</td><td>%s</td><td>%s</td><td class="strip-cell">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->escape($project['slug']),
                $this->escape($project['name']),
                \count($project['observations']),
                $this->escape($latest['run']),
                $this->outcome($latest),
                $this->strip($project['observations']),
                $this->countCell($latest['passed']),
                $this->countCell($latest['failed']),
                $this->countCell($latest['errors']),
                $this->countCell($latest['knownGaps']),
                $this->countCell($latest['files']),
                $this->durationCell($latest['milliseconds']),
            );
        }

        return '<section><h2>Projects</h2><div class="scroll"><table>'
            .'<caption>Latest recorded observation per project. Counts and durations describe that observation only.</caption>'
            .'<thead><tr><th scope="col">Project</th><th scope="col">Observations</th><th scope="col">Latest run</th><th scope="col">Latest outcome</th>'
            .'<th scope="col">Recent outcomes</th><th scope="col">Checks passed</th><th scope="col">Checks failed</th><th scope="col">Errors</th>'
            .'<th scope="col">Known gaps</th><th scope="col">Files analyzed</th><th scope="col">Duration</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table></div></section>';
    }

    /**
     * @param list<Observation> $observations
     */
    private function strip(array $observations): string
    {
        $recent = \array_slice($observations, -self::STRIP_LENGTH);
        $chips = '';
        $labels = [];
        foreach ($recent as $observation) {
            $label = $this->outcomeLabel($observation['outcome']);
            $labels[] = strtolower($label);
            $chips .= \sprintf(
                '<span class="chip %s" style="background:var(--%s)" title="%s"></span>',
                $this->outcomeClass($observation['outcome']),
                isset(self::OUTCOMES[$observation['outcome']]) ? $observation['outcome'] : 'none',
                $this->escape($observation['run'].': '.$label.' ('.$this->modeLabel($observation['analysisMode']).')'),
            );
        }

        return \sprintf(
            '<span class="strip" role="img" aria-label="%s">%s</span>',
            $this->escape('Recent outcomes, oldest first: '.implode(', ', $labels)),
            $chips,
        );
    }

    /**
     * @param list<ProjectView> $projects
     */
    private function selector(array $projects): string
    {
        $options = '<option value="">All projects</option>';
        foreach ($projects as $project) {
            $options .= \sprintf('<option value="%s">%s</option>', $this->escape($project['slug']), $this->escape($project['name']));
        }

        return '<div class="filter"><label for="project-filter">Show details for</label> <select id="project-filter">'.$options.'</select>'
            .'<noscript><span class="note">JavaScript is disabled: every project is shown below.</span></noscript></div>';
    }

    /**
     * @param ProjectView $project
     */
    private function panel(array $project): string
    {
        $observations = $project['observations'];
        $latest = $observations[\count($observations) - 1];
        $comparablePairs = \count(array_filter($project['comparable']));

        return \sprintf(
            '<section class="panel" id="%s" data-project-panel="%s"><h2>%s</h2>'
            .'<p class="note">%s recorded, %d of %s share a comparison fingerprint with the observation before.</p>'
            .'%s%s%s</section>',
            $this->escape($project['slug']),
            $this->escape($project['slug']),
            $this->escape($project['name']),
            $this->plural(\count($observations), 'observation'),
            $comparablePairs,
            $this->plural(max(0, \count($observations) - 1), 'consecutive pair'),
            $this->metadata($latest),
            $this->charts($observations, $project['comparable']),
            $this->history($observations, $project['comparable']),
        );
    }

    /**
     * @param Observation $latest
     */
    private function metadata(array $latest): string
    {
        $items = [
            'Run' => $this->escape($latest['run']),
            'Recorded' => $this->textCell($latest['time']),
            'Outcome' => $this->outcome($latest),
            'Analysis' => $this->modeLabel($latest['analysisMode']),
            'Failing layers' => [] === $latest['layers'] ? '<span class="unknown">none recorded</span>' : $this->escape(implode(', ', $latest['layers'])),
            'Revision' => $this->fingerprintCell($latest['revision']),
            'Dependencies' => $this->fingerprintCell($latest['dependencies']),
            'Environment' => $this->textCell($latest['environment']),
            'Framework' => $this->textCell($latest['framework']),
            'Server version' => $this->textCell($latest['serverVersion']),
            'Expectations' => $this->fingerprintCell($latest['expectations']),
            'Check set' => $this->fingerprintCell($latest['checkSet']),
            'Comparison' => $this->fingerprintCell($latest['comparison']),
        ];
        $rendered = '';
        foreach ($items as $term => $value) {
            $rendered .= \sprintf('<div><dt>%s</dt><dd>%s</dd></div>', $this->escape($term), $value);
        }

        return '<h3>Latest observation</h3><dl class="meta">'.$rendered.'</dl>';
    }

    /**
     * @param list<Observation> $observations
     * @param list<bool>        $comparable
     */
    private function charts(array $observations, array $comparable): string
    {
        $labels = array_map(static fn (array $observation): string => $observation['run'], $observations);

        $charts = $this->chart(
            'Checks',
            'Recorded check outcomes across cold and warm phases. Errors are checks that could not be verified; total checks are the sum of these outcomes.',
            $labels,
            [
                ['label' => 'Checks passed (verified)', 'values' => $this->series($observations, 'passed')],
                ['label' => 'Checks failed (verified)', 'values' => $this->series($observations, 'failed')],
                ['label' => 'Errors / unverified', 'values' => $this->series($observations, 'errors')],
            ],
            $comparable,
        );
        $charts .= $this->chart(
            'Known gaps and diagnostics',
            'Known gaps are confirmed analyzer limitations retained explicitly until fixed. Changed expectations can also change this count; it is not a support score.',
            $labels,
            [
                ['label' => 'Known gaps', 'values' => $this->series($observations, 'knownGaps')],
                ['label' => 'Diagnostics', 'values' => $this->series($observations, 'diagnostics')],
            ],
            $comparable,
        );
        $charts .= $this->chart(
            'Files analyzed',
            'Files the run analyzed. A drop usually means a smaller or partly indexed project, not a better result.',
            $labels,
            [['label' => 'Files analyzed', 'values' => $this->series($observations, 'files')]],
            $comparable,
        );
        $charts .= $this->chart(
            'Requests',
            'Language server requests issued by the scenarios of the run.',
            $labels,
            [
                ['label' => 'Requests', 'values' => $this->series($observations, 'requests')],
                ['label' => 'Scenarios', 'values' => $this->series($observations, 'scenarios')],
            ],
            $comparable,
        );
        $charts .= $this->chart(
            'Duration',
            'Project total includes setup, so it moves with machine load and cache state.',
            $labels,
            [
                ['label' => 'Project total', 'values' => $this->series($observations, 'milliseconds')],
                ['label' => 'Scenarios', 'values' => $this->series($observations, 'scenarioMilliseconds')],
            ],
            $comparable,
            true,
        );

        return '<h3>Measurements</h3><div class="charts">'.$charts.'</div>';
    }

    /**
     * @param list<Observation> $observations
     *
     * @return list<float|null>
     */
    private function series(array $observations, string $key): array
    {
        $values = [];
        foreach ($observations as $observation) {
            $value = $observation[$key] ?? null;
            $values[] = \is_int($value) ? (float) $value : (\is_float($value) ? $value : null);
        }

        return $values;
    }

    /**
     * @param list<string> $labels
     * @param list<Series> $series
     * @param list<bool>   $comparable
     */
    private function chart(string $title, string $note, array $labels, array $series, array $comparable, bool $duration = false): string
    {
        $measured = [];
        foreach ($series as $line) {
            foreach ($line['values'] as $value) {
                if (null !== $value) {
                    $measured[] = $value;
                }
            }
        }
        $body = [] === $measured
            ? '<p class="unknown">No measurement recorded for this chart.</p>'
            : $this->plot($labels, $series, $comparable, max($measured), $duration);

        $legend = '';
        foreach ($series as $index => $line) {
            $legend .= \sprintf('<li><span class="key s%d"></span>%s</li>', $index + 1, $this->escape($line['label']));
        }

        return \sprintf(
            '<figure class="chart"><figcaption>%s</figcaption>%s<ul class="legend">%s</ul><p class="note">%s</p></figure>',
            $this->escape($title),
            $body,
            $legend,
            $this->escape($note),
        );
    }

    /**
     * @param list<string> $labels
     * @param list<Series> $series
     * @param list<bool>   $comparable
     */
    private function plot(array $labels, array $series, array $comparable, float $highest, bool $duration): string
    {
        $count = \count($labels);
        $scale = $highest > 0.0 ? $highest : 1.0;
        if (!$duration && $scale > 1.0) {
            $scale = ceil($scale / 2.0) * 2.0;
        }
        $bottom = self::HEIGHT - self::BOTTOM;
        $svg = '';
        foreach (!$duration && $scale <= 1.0 ? [1.0, 0.0] : [1.0, 0.5, 0.0] as $fraction) {
            $y = self::TOP + (self::HEIGHT - self::TOP - self::BOTTOM) * (1.0 - $fraction);
            $svg .= \sprintf(
                '<line class="grid" x1="%s" y1="%s" x2="%s" y2="%s"></line><text class="tick" x="%s" y="%s" text-anchor="end">%s</text>',
                $this->coordinate(self::LEFT),
                $this->coordinate($y),
                $this->coordinate(self::WIDTH - self::RIGHT),
                $this->coordinate($y),
                $this->coordinate(self::LEFT - 4.0),
                $this->coordinate($y + 3.0),
                $this->escape($this->measure($scale * $fraction, $duration)),
            );
        }

        foreach ($comparable as $index => $flag) {
            if (0 === $index || $flag) {
                continue;
            }
            $x = ($this->x($index - 1, $count) + $this->x($index, $count)) / 2.0;
            $svg .= \sprintf(
                '<line class="break" x1="%s" y1="%s" x2="%s" y2="%s"><title>%s</title></line>',
                $this->coordinate($x),
                $this->coordinate(self::TOP),
                $this->coordinate($x),
                $this->coordinate($bottom),
                $this->escape(\sprintf('%s cannot be compared with the previous observation', $labels[$index])),
            );
        }

        foreach ($series as $index => $line) {
            $svg .= $this->line($line, $index + 1, $labels, $comparable, $scale, $duration);
        }

        $svg .= \sprintf(
            '<text class="tick" x="%s" y="%s" text-anchor="start">%s</text>',
            $this->coordinate(self::LEFT),
            $this->coordinate(self::HEIGHT - 8.0),
            $this->escape($labels[0]),
        );
        if ($count > 1) {
            $svg .= \sprintf(
                '<text class="tick" x="%s" y="%s" text-anchor="end">%s</text>',
                $this->coordinate(self::WIDTH - self::RIGHT),
                $this->coordinate(self::HEIGHT - 8.0),
                $this->escape($labels[$count - 1]),
            );
        }

        return \sprintf(
            '<svg viewBox="0 0 %s %s" role="img" aria-label="%s">%s</svg>',
            $this->coordinate(self::WIDTH),
            $this->coordinate(self::HEIGHT),
            $this->escape(\sprintf('%d observations, highest value %s', $count, $this->measure($highest, $duration))),
            $svg,
        );
    }

    /**
     * @param Series       $series
     * @param list<string> $labels
     * @param list<bool>   $comparable
     */
    private function line(array $series, int $key, array $labels, array $comparable, float $scale, bool $duration): string
    {
        $count = \count($series['values']);
        $svg = '';
        $segment = [];
        foreach ($series['values'] as $index => $value) {
            $joins = 0 !== $index && ($comparable[$index] ?? false) && null !== ($series['values'][$index - 1] ?? null);
            if (!$joins || null === $value) {
                $svg .= $this->segment($segment, $key);
                $segment = [];
            }
            if (null === $value) {
                continue;
            }
            $x = $this->x($index, $count);
            $y = self::TOP + (self::HEIGHT - self::TOP - self::BOTTOM) * (1.0 - $value / $scale);
            $segment[] = $this->coordinate($x).','.$this->coordinate($y);
            $svg .= \sprintf(
                '<circle class="dot s%d" cx="%s" cy="%s" r="%s"><title>%s</title></circle>',
                $key,
                $this->coordinate($x),
                $this->coordinate($y),
                $count > 40 ? '1.4' : '2.4',
                $this->escape(\sprintf('%s on %s: %s', $series['label'], $labels[$index] ?? '', $this->measure($value, $duration))),
            );
        }

        return $svg.$this->segment($segment, $key);
    }

    /**
     * @param list<string> $points
     */
    private function segment(array $points, int $key): string
    {
        if (\count($points) < 2) {
            return '';
        }

        return \sprintf('<polyline class="line s%d" points="%s"></polyline>', $key, implode(' ', $points));
    }

    private function x(int $index, int $count): float
    {
        $plot = self::WIDTH - self::LEFT - self::RIGHT;

        return $count > 1 ? self::LEFT + $plot * $index / ($count - 1) : self::LEFT + $plot / 2.0;
    }

    /**
     * @param list<Observation> $observations
     * @param list<bool>        $comparable
     */
    private function history(array $observations, array $comparable): string
    {
        $rows = '';
        foreach (array_reverse($observations, true) as $index => $observation) {
            $rows .= \sprintf(
                '<tr><th scope="row">%s</th><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="wrap">%s</td></tr>',
                $this->escape($observation['run']),
                $this->textCell($observation['time']),
                $this->outcome($observation),
                $this->countCell($observation['scenarios']),
                $this->countCell($observation['checks']),
                $this->countCell($observation['passed']),
                $this->countCell($observation['failed']),
                $this->countCell($observation['errors']),
                $this->countCell($observation['knownGaps']),
                $this->countCell($observation['diagnostics']),
                $this->countCell($observation['files']),
                $this->countCell($observation['requests']),
                $this->durationCell($observation['milliseconds']),
                $this->change($observations[$index - 1] ?? null, $observation, $comparable[$index] ?? false),
            );
        }

        return '<h3>History</h3><div class="scroll"><table>'
            .'<caption>Newest first. Total checks are passed plus failed plus unverified/error outcomes.</caption>'
            .'<thead><tr><th scope="col">Run</th><th scope="col">Recorded</th><th scope="col">Outcome</th><th scope="col">Scenarios</th>'
            .'<th scope="col">Total checks</th><th scope="col">Passed</th><th scope="col">Failed</th><th scope="col">Errors</th>'
            .'<th scope="col">Known gaps</th><th scope="col">Diagnostics</th><th scope="col">Files</th><th scope="col">Requests</th>'
            .'<th scope="col">Duration</th><th scope="col">Compared with previous</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table></div>';
    }

    /**
     * @param Observation|null $previous
     * @param Observation      $current
     */
    private function change(?array $previous, array $current, bool $comparable): string
    {
        if (null === $previous) {
            return '<span class="unknown">first observation</span>';
        }
        $transition = $previous['outcome'] === $current['outcome'] ? '' : $this->escape($this->outcomeLabel($previous['outcome']).' → '.$this->outcomeLabel($current['outcome']));
        if (!$comparable) {
            $reason = match (true) {
                !$previous['finalized'], !$current['finalized'], 'incomplete' === $previous['outcome'], 'incomplete' === $current['outcome'] => 'not comparable, run incomplete',
                null === $current['analysisMode'], $previous['analysisMode'] !== $current['analysisMode'] => 'not comparable, analysis mode changed or unknown',
                null === $previous['comparison'], null === $current['comparison'] => 'not comparable, comparison fingerprint unknown',
                default => 'not comparable, revision, dependencies, environment or expectations changed',
            };

            return ('' === $transition ? '' : $transition.'; ').'<span class="unknown">'.$this->escape($reason).'</span>';
        }

        $parts = '' === $transition ? [] : [$transition];
        foreach (['passed', 'failed', 'errors'] as $field) {
            $now = $current[$field];
            $before = $previous[$field];
            if (null === $now || null === $before) {
                $parts[] = \sprintf('<span class="unknown">%s unknown</span>', $this->escape($field));

                continue;
            }
            $delta = $now - $before;
            if (0 === $delta) {
                continue;
            }
            $better = 'passed' === $field ? $delta > 0 : $delta < 0;
            $parts[] = \sprintf(
                '<span class="%s">%s %s%d</span>',
                $better ? 'better' : 'worse',
                $this->escape($field),
                $delta > 0 ? '+' : '',
                $delta,
            );
        }

        return [] === $parts ? 'no change' : implode(', ', $parts);
    }

    /**
     * @param Observation $observation
     */
    private function outcome(array $observation): string
    {
        $badge = \sprintf(
            '<span class="badge %s">%s</span>',
            $this->outcomeClass($observation['outcome']),
            $this->escape($this->outcomeLabel($observation['outcome'])),
        );
        $badge .= ' <span class="analysis-mode">'.$this->modeLabel($observation['analysisMode']).'</span>';
        if ([] !== $observation['layers']) {
            $badge .= \sprintf(' <span class="layers">%s</span>', $this->escape(implode(', ', $observation['layers'])));
        }
        if (!$observation['finalized']) {
            $badge .= ' <span class="unknown">not finalized</span>';
        }

        return $badge;
    }

    private function modeLabel(?string $analysisMode): string
    {
        return match ($analysisMode) {
            'source-only' => 'Source-only',
            'runtime' => 'Runtime',
            default => 'Unknown analysis',
        };
    }

    private function outcomeLabel(string $outcome): string
    {
        return self::OUTCOMES[$outcome] ?? ('' === $outcome ? 'Unknown' : $outcome);
    }

    private function outcomeClass(string $outcome): string
    {
        return isset(self::OUTCOMES[$outcome]) ? 'outcome-'.$outcome : 'outcome-unknown';
    }

    private function countCell(?int $value): string
    {
        return null === $value ? '<span class="unknown">unknown</span>' : $this->escape(number_format($value, 0, '.', ','));
    }

    private function durationCell(?float $value): string
    {
        return null === $value ? '<span class="unknown">unknown</span>' : $this->escape($this->measure($value, true));
    }

    private function textCell(?string $value): string
    {
        return null === $value ? '<span class="unknown">unknown</span>' : $this->escape($value);
    }

    private function fingerprintCell(?string $value): string
    {
        if (null === $value) {
            return '<span class="unknown">unknown</span>';
        }

        return \sprintf('<code title="%s">%s</code>', $this->escape($value), $this->escape(substr($value, 0, 12)));
    }

    private function measure(float $value, bool $duration): string
    {
        if (!$duration) {
            return number_format($value, 0, '.', ',');
        }

        if ($value > 0.0 && $value < 10.0) {
            return $this->coordinate($value).' ms';
        }

        return $value >= 1000.0
            ? number_format($value / 1000.0, 1, '.', ',').' s'
            : number_format($value, 0, '.', ',').' ms';
    }

    private function plural(int $count, string $noun): string
    {
        return \sprintf('%d %s', $count, 1 === $count ? $noun : $noun.'s');
    }

    private function coordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function textList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function count(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    private function number(mixed $value): ?float
    {
        if (\is_float($value) && is_finite($value)) {
            return $value;
        }

        return \is_int($value) ? (float) $value : null;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private function page(string $body): string
    {
        return '<!DOCTYPE html>'."\n"
            .'<html lang="en">'."\n"
            .'<head>'."\n"
            .'<meta charset="utf-8">'."\n"
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'."\n"
            .'<title>Symfony LSP dogfood history</title>'."\n"
            .'<style>'.$this->styles().'</style>'."\n"
            .'</head>'."\n"
            .'<body>'."\n"
            .'<h1>Symfony LSP dogfood history</h1>'."\n"
            .$body."\n"
            .'<script>'.$this->script().'</script>'."\n"
            .'</body>'."\n"
            .'</html>'."\n";
    }

    private function styles(): string
    {
        return <<<'CSS'
            :root{color-scheme:light dark;--bg:#fff;--fg:#1b1b1f;--muted:#5b5b66;--line:#d5d5de;--card:#f7f7fa;
            --passed:#1a7f37;--failed:#c02020;--blocked:#9a5b00;--incomplete:#4a5a8a;--none:#7a7a86;
            --s1:#1f6fb4;--s2:#c02020;--s3:#6a4a9a}
            @media (prefers-color-scheme:dark){:root{--bg:#16161a;--fg:#e9e9f0;--muted:#a2a2b0;--line:#33333d;--card:#1e1e24;
            --passed:#3fb95f;--failed:#ef5350;--blocked:#d79a3a;--incomplete:#8ba2e0;--none:#8a8a96;--s1:#63a4de;--s2:#ef5350;--s3:#b394e0}}
            *{box-sizing:border-box}
            body{margin:0 auto;padding:1.2rem;max-width:78rem;background:var(--bg);color:var(--fg);
            font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
            h1{font-size:1.35rem;margin:0 0 .4rem}h2{font-size:1.1rem;margin:1.6rem 0 .4rem}h3{font-size:.95rem;margin:1rem 0 .3rem}
            a{color:inherit}
            .summary{margin:.2rem 0;font-weight:600}
            .note{margin:.2rem 0;color:var(--muted);font-size:.8rem}
            .empty{margin:1rem 0;font-size:1rem}
            .scroll{overflow-x:auto}
            table{border-collapse:collapse;width:100%;font-size:.82rem}
            caption{text-align:left;color:var(--muted);font-size:.8rem;padding-bottom:.35rem}
            th,td{border-bottom:1px solid var(--line);padding:.28rem .5rem;text-align:right;white-space:nowrap;vertical-align:top}
            thead th{color:var(--muted);font-weight:600}
            th:first-child,td:first-child{text-align:left}
            td.wrap{white-space:normal;text-align:left;min-width:12rem}
            td.strip-cell{text-align:left}
            .badge{display:inline-block;padding:0 .4rem;border-radius:.6rem;color:#fff;font-size:.75rem}
            .analysis-mode{display:inline-block;border:1px solid var(--line);border-radius:.3rem;padding:0 .3rem;font-size:.75rem;color:var(--fg)}
            .outcome-passed{background:var(--passed)}.outcome-failed{background:var(--failed)}
            .outcome-blocked{background:var(--blocked)}.outcome-incomplete{background:var(--incomplete)}
            .outcome-unknown{background:var(--none)}
            .layers{color:var(--muted);font-size:.75rem}
            .unknown{color:var(--muted);font-style:italic}
            .better{color:var(--passed)}.worse{color:var(--failed)}
            .strip{display:inline-flex;gap:2px}
            .strip .chip{width:8px;height:15px;border-radius:2px;background:var(--none)}
            .filter{margin:1.4rem 0 .6rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
            .filter label{font-weight:600}
            .panel{border-top:1px solid var(--line);padding-top:.4rem}
            .meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:.3rem .8rem;margin:.3rem 0}
            .meta dt{color:var(--muted);font-size:.72rem}.meta dd{margin:0;font-size:.82rem;overflow-wrap:anywhere}
            .charts{display:grid;grid-template-columns:repeat(auto-fit,minmax(22rem,1fr));gap:.8rem}
            figure.chart{margin:0;padding:.6rem;border:1px solid var(--line);border-radius:.5rem;background:var(--card)}
            figcaption{font-weight:600;font-size:.85rem;margin-bottom:.2rem}
            svg{display:block;width:100%;height:auto}
            .grid{stroke:var(--line);stroke-dasharray:2 3}
            .break{stroke:var(--blocked);stroke-dasharray:3 3}
            .tick{fill:var(--muted);font-size:9px;font-family:inherit}
            .line{fill:none;stroke-width:1.8}
            .line.s1{stroke:var(--s1)}.line.s2{stroke:var(--s2)}.line.s3{stroke:var(--s3)}
            .dot.s1{fill:var(--s1)}.dot.s2{fill:var(--s2)}.dot.s3{fill:var(--s3)}
            .legend{display:flex;flex-wrap:wrap;gap:.5rem;list-style:none;margin:.3rem 0 0;padding:0;font-size:.75rem;color:var(--muted)}
            .legend .key{display:inline-block;width:.55rem;height:.55rem;border-radius:50%;margin-right:.2rem;vertical-align:middle}
            .legend .key.s1{background:var(--s1)}.legend .key.s2{background:var(--s2)}.legend .key.s3{background:var(--s3)}
            CSS;
    }

    private function script(): string
    {
        return <<<'JS'

            (function () {
                var select = document.getElementById('project-filter');
                var panels = document.querySelectorAll('[data-project-panel]');
                if (!select || 0 === panels.length) {
                    return;
                }
                function apply() {
                    var value = select.value;
                    for (var i = 0; i < panels.length; i++) {
                        panels[i].hidden = '' !== value && panels[i].getAttribute('data-project-panel') !== value;
                    }
                }
                select.addEventListener('change', apply);
                document.querySelectorAll('a[href^="#project-"]').forEach(function (link) {
                    link.addEventListener('click', function () {
                        select.value = link.getAttribute('href').slice(1);
                        apply();
                    });
                });
                apply();
            })();
            JS;
    }
}
