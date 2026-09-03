<?php

namespace Symfony\Lsp\Check;

final class CheckProfileReporter
{
    public function render(CheckResult $result): string
    {
        $profile = $result->profile;
        if (null === $profile) {
            return '';
        }

        $projects = [];
        foreach ($result->projects as $project) {
            $projects[$project->id] = $project;
        }

        $lines = ['Timing profile:'];
        $lines[] = $this->line(1, 'Total', $profile->totalMilliseconds);
        $lines[] = '  Phases:';
        foreach ([
            'startup' => 'Executable startup',
            'configuration' => 'Configuration',
            'projectDiscovery' => 'Project discovery',
            'fileSelection' => 'File selection',
            'projectAnalysis' => 'Project analysis',
            'diagnostics' => 'Diagnostics',
            'resultProcessing' => 'Result processing',
        ] as $name => $label) {
            if (null !== $profile->phasesMilliseconds[$name]) {
                $lines[] = $this->line(2, $label, $profile->phasesMilliseconds[$name]);
            }
        }
        if (null !== $profile->baselineMatchingMilliseconds) {
            $lines[] = $this->line(3, 'Baseline matching', $profile->baselineMatchingMilliseconds);
        }

        if ([] !== $profile->projects) {
            $lines[] = '  Projects:';
        }
        foreach ($profile->projects as $projectProfile) {
            $lines[] = $this->line(2, \sprintf(
                'Project %s (%d %s)',
                $projectProfile->id,
                $projectProfile->files,
                1 === $projectProfile->files ? 'file' : 'files',
            ), array_sum(array_filter(
                $projectProfile->phasesMilliseconds,
                static fn (?float $milliseconds): bool => null !== $milliseconds,
            )));
            foreach ([
                'sourceIndex' => 'Source indexing',
                'filePreparation' => 'File preparation',
                'runtimeIndex' => 'Runtime indexing',
                'diagnostics' => 'Diagnostics',
            ] as $name => $label) {
                if (null === $projectProfile->phasesMilliseconds[$name]) {
                    continue;
                }
                $lines[] = $this->line(3, $label, $projectProfile->phasesMilliseconds[$name]);
                if ('runtimeIndex' !== $name) {
                    continue;
                }

                $runtimeTimings = ($projects[$projectProfile->id] ?? null)?->runtime['timings'] ?? null;
                if (\is_array($runtimeTimings) && (\is_int($runtimeTimings['totalMilliseconds'] ?? null) || \is_float($runtimeTimings['totalMilliseconds'] ?? null))) {
                    $lines[] = $this->line(4, 'Application bridge', (float) $runtimeTimings['totalMilliseconds']);
                    if (\is_array($runtimeTimings['sectionsMilliseconds'] ?? null)) {
                        $lines[] = '        Runtime sections:';
                        foreach ($this->largest($runtimeTimings['sectionsMilliseconds']) as $section => $milliseconds) {
                            $lines[] = $this->line(5, $section, $milliseconds);
                        }
                    }
                }
            }

            if ([] !== $projectProfile->diagnosticProvidersMilliseconds) {
                $lines[] = '      Diagnostic providers:';
                foreach ($this->largest($projectProfile->diagnosticProvidersMilliseconds) as $provider => $milliseconds) {
                    $lines[] = $this->line(4, $provider, $milliseconds);
                }
            }
            if ([] !== $projectProfile->slowestFilesMilliseconds) {
                $lines[] = '      Slowest diagnostic files:';
                foreach ($projectProfile->slowestFilesMilliseconds as $path => $milliseconds) {
                    $lines[] = $this->line(4, $path, $milliseconds);
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function line(int $indent, string $label, float $milliseconds): string
    {
        return \sprintf('%s%-38s %10s', str_repeat('  ', $indent), $label, $this->duration($milliseconds));
    }

    private function duration(float $milliseconds): string
    {
        return $milliseconds >= 1_000
            ? \sprintf('%.2f s', $milliseconds / 1_000)
            : \sprintf('%.1f ms', $milliseconds);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, float>
     */
    private function largest(array $values): array
    {
        $normalized = [];
        foreach ($values as $name => $milliseconds) {
            if (!\is_string($name) || (!\is_int($milliseconds) && !\is_float($milliseconds))) {
                continue;
            }
            $normalized[$name] = (float) $milliseconds;
        }
        uksort($normalized, static fn (string $left, string $right): int => [$normalized[$right], $left] <=> [$normalized[$left], $right]);

        return \array_slice($normalized, 0, 10, true);
    }
}
