<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class RunClassifier
{
    /**
     * @param 'runtime'|'source-only' $analysisMode the mode the run was requested in, never the mode it reported
     *
     * @return list<string>
     */
    public function classify(HarnessResult $run, string $analysisMode = 'runtime'): array
    {
        if ($run->timedOut) {
            return ['timeout'];
        }
        if (null === $run->result || 0 !== $run->exitCode) {
            return ['process'];
        }
        $layers = [];
        if ($analysisMode !== (\array_key_exists('analysisMode', $run->result) ? $run->result['analysisMode'] : 'runtime')) {
            $layers[] = 'analysis-mode';
        }
        $source = $this->indexState($run->result, 'source');
        if ('failed' === $source) {
            $layers[] = 'source-index';
        } elseif ('ready' !== $source) {
            $layers[] = 'timeout';
        }
        $runtime = $this->indexState($run->result, 'runtime');
        if ('source-only' === $analysisMode) {
            $status = $run->result['status'] ?? null;
            if (('disabled' !== $runtime || !\is_array($status) || false !== ($status['runtimeEnabled'] ?? null)) && !\in_array('analysis-mode', $layers, true)) {
                $layers[] = 'analysis-mode';
            }
        } elseif (\in_array($runtime, ['failed', 'partial', 'stale', 'disabled'], true)) {
            $status = $run->result['status'] ?? null;
            $runtimeStatus = \is_array($status) ? ($status['runtime'] ?? null) : null;
            $stage = \is_array($runtimeStatus) ? ($runtimeStatus['stage'] ?? null) : null;
            $layers[] = 'bootstrap' === $stage ? 'bootstrap' : 'runtime-index';
        } elseif ('ready' !== $runtime && !\in_array('timeout', $layers, true)) {
            $layers[] = 'timeout';
        }
        if (!$this->hasVerifiedScenarios($run->result)) {
            $layers[] = 'scenario';
        }
        if ([] !== ($run->result['violations'] ?? [])) {
            $layers[] = 'request';
        }
        if (0 !== ($run->result['exitCode'] ?? null) || null !== ($run->result['serverError'] ?? null)) {
            $layers[] = 'process';
        }

        return $layers;
    }

    /** @param array<mixed> $result */
    public function indexState(array $result, string $section): string
    {
        $status = $result['status'] ?? null;
        if (!\is_array($status)) {
            return 'unknown';
        }
        $part = $status[$section] ?? null;

        return \is_array($part) && \is_string($part['state'] ?? null) ? $part['state'] : 'unknown';
    }

    /** @param array<mixed> $result */
    private function hasVerifiedScenarios(array $result): bool
    {
        $scenarios = $result['scenarios'] ?? null;
        if (!\is_array($scenarios) || !array_is_list($scenarios) || [] === $scenarios
            || \count($scenarios) !== ($result['scenarioCount'] ?? null)
            || 0 !== ($result['assertionFailures'] ?? null)
        ) {
            return false;
        }
        $ids = [];
        foreach ($scenarios as $scenario) {
            if (!\is_array($scenario) || 'pass' !== ($scenario['status'] ?? null)
                || !\is_string($scenario['id'] ?? null) || '' === $scenario['id'] || isset($ids[$scenario['id']])
                || !\is_array($scenario['checks'] ?? null) || [] === $scenario['checks']
                || [] !== ($scenario['failures'] ?? [])
            ) {
                return false;
            }
            $ids[$scenario['id']] = true;
            foreach ($scenario['checks'] as $check) {
                if (!\is_array($check) || 'pass' !== ($check['status'] ?? null)
                    || !\is_string($check['method'] ?? null) || !\is_string($check['phase'] ?? null)
                    || !\is_string($check['fingerprint'] ?? null) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $check['fingerprint'])
                    || [] !== ($check['failures'] ?? [])
                ) {
                    return false;
                }
            }
        }

        return true;
    }
}
