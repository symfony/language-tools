<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class RunClassifier
{
    /**
     * @return list<string> failed layers, empty on success
     */
    public function classify(HarnessResult $run): array
    {
        if ($run->timedOut) {
            return ['timeout'];
        }
        if (null === $run->result || 0 !== $run->exitCode) {
            return ['process'];
        }
        $layers = [];
        $timedOut = false;
        $source = $this->indexState($run->result, 'source');
        if ('failed' === $source) {
            $layers[] = 'source-index';
        } elseif ('ready' !== $source) {
            $timedOut = true;
        }
        $runtime = $this->indexState($run->result, 'runtime');
        if (\in_array($runtime, ['failed', 'partial', 'stale'], true)) {
            $layers[] = 'bootstrap' === $this->runtimeStage($run->result) ? 'bootstrap' : 'runtime-index';
        } elseif ('ready' !== $runtime) {
            $timedOut = true;
        }
        if ($timedOut) {
            $layers[] = 'timeout';
        }
        if ($this->hasRequestError($run->result) || [] !== ($run->result['violations'] ?? [])) {
            $layers[] = 'request';
        }
        if (0 !== ($run->result['exitCode'] ?? 0) || null !== ($run->result['serverError'] ?? null)) {
            $layers[] = 'process';
        }

        return $layers;
    }

    /**
     * @param array<mixed> $result
     */
    public function indexState(array $result, string $section): string
    {
        $status = $result['status'] ?? null;
        if (!\is_array($status)) {
            return 'unknown';
        }
        $part = $status[$section] ?? null;

        return \is_array($part) && \is_string($part['state'] ?? null) ? $part['state'] : 'unknown';
    }

    /**
     * @param array<mixed> $result
     */
    private function runtimeStage(array $result): ?string
    {
        $status = $result['status'] ?? null;
        if (!\is_array($status) || !\is_array($status['runtime'] ?? null)) {
            return null;
        }
        $stage = $status['runtime']['stage'] ?? null;

        return \is_string($stage) ? $stage : null;
    }

    /**
     * @param array<mixed> $result
     */
    private function hasRequestError(array $result): bool
    {
        foreach (\is_array($result['probes'] ?? null) ? $result['probes'] : [] as $probe) {
            if (!\is_array($probe) || !\is_array($probe['requests'] ?? null)) {
                continue;
            }
            foreach ($probe['requests'] as $request) {
                if (\is_array($request) && null !== ($request['error'] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }
}
