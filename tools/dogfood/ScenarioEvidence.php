<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ScenarioEvidence
{
    public function hasPositiveCheck(ScenarioManifest $manifest): bool
    {
        foreach ($manifest->scenarios as $scenario) {
            foreach ([$scenario['expect'], $scenario['edit']['expect'] ?? [], $scenario['edit']['afterFix'] ?? []] as $expectations) {
                foreach ($expectations as $expectation) {
                    if ([] !== ($expectation['includes'] ?? []) || [] !== ($expectation['equals'] ?? [])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param array<mixed> $result */
    public function covers(ScenarioManifest $manifest, array $result): bool
    {
        $scenarios = $result['scenarios'] ?? null;
        if (!\is_array($scenarios) || \count($scenarios) !== \count($manifest->scenarios)) {
            return false;
        }
        $actual = [];
        foreach ($scenarios as $scenario) {
            if (!\is_array($scenario) || !\is_string($scenario['id'] ?? null) || isset($actual[$scenario['id']]) || !\is_array($scenario['checks'] ?? null)) {
                return false;
            }
            $actual[$scenario['id']] = [];
            foreach ($scenario['checks'] as $check) {
                if (!\is_array($check) || !\is_string($check['phase'] ?? null) || !\is_string($check['method'] ?? null)) {
                    return false;
                }
                $key = $check['phase'].'/'.$check['method'];
                if (isset($actual[$scenario['id']][$key])) {
                    return false;
                }
                $actual[$scenario['id']][$key] = true;
            }
        }
        foreach ($manifest->scenarios as $scenario) {
            $phases = ['baseline' => $scenario['expect']];
            if (isset($scenario['edit'])) {
                $phases['edit'] = $scenario['edit']['expect'];
                $phases['restored'] = $scenario['expect'];
                if (isset($scenario['edit']['afterFix'])) {
                    $phases['afterFix'] = $scenario['edit']['afterFix'];
                }
            }
            foreach ($phases as $phase => $expectations) {
                foreach (array_keys($expectations) as $method) {
                    if (!isset($actual[$scenario['id']][$phase.'/'.$method])) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $result
     *
     * @return list<string>
     */
    public function semantics(array $result): array
    {
        $semantics = [];
        foreach (\is_array($result['scenarios'] ?? null) ? $result['scenarios'] : [] as $scenario) {
            if (!\is_array($scenario) || !\is_string($scenario['id'] ?? null) || !\is_array($scenario['checks'] ?? null)) {
                continue;
            }
            foreach ($scenario['checks'] as $check) {
                if (\is_array($check)) {
                    $semantics[] = json_encode([$scenario['id'], $check['phase'] ?? null, $check['method'] ?? null, $check['fingerprint'] ?? null], \JSON_THROW_ON_ERROR);
                }
            }
        }
        sort($semantics, \SORT_STRING);

        return $semantics;
    }

    /**
     * @param list<array<string, mixed>> $diagnostics
     *
     * @return list<string>
     */
    public function diagnostics(array $diagnostics): array
    {
        $normalized = [];
        foreach ($diagnostics as $diagnostic) {
            $normalized[] = json_encode($this->canonical(array_intersect_key($diagnostic, array_flip(['path', 'code', 'severity', 'range', 'messageHash']))), \JSON_THROW_ON_ERROR);
        }
        sort($normalized, \SORT_STRING);

        return $normalized;
    }

    /**
     * @param list<array<string, mixed>> $expected
     * @param list<array<string, mixed>> $actual
     */
    public function diagnosticDifference(array $expected, array $actual): string
    {
        $remaining = array_count_values($this->diagnostics($expected));
        $added = [];
        foreach ($this->diagnostics($actual) as $entry) {
            if (0 < ($remaining[$entry] ?? 0)) {
                --$remaining[$entry];
            } else {
                $added[] = $entry;
            }
        }
        $removed = [];
        foreach ($remaining as $entry => $count) {
            for ($index = 0; $index < $count; ++$index) {
                $removed[] = $entry;
            }
        }

        return \sprintf(
            'Whole-project diagnostics differ: %d added, %d removed.%s%s',
            \count($added),
            \count($removed),
            [] === $added ? '' : ' Added: '.implode('; ', \array_slice($added, 0, 3)),
            [] === $removed ? '' : ' Removed: '.implode('; ', \array_slice($removed, 0, 3)),
        );
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function canonical(array $value): array
    {
        foreach ($value as $key => $child) {
            if (\is_array($child)) {
                $value[$key] = $this->canonical($child);
            }
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
