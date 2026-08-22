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
        if ('ready' !== $this->indexState($run->result, 'source')) {
            $layers[] = 'source-index';
        }
        if ('ready' !== $this->indexState($run->result, 'runtime')) {
            $layers[] = 'runtime-index';
        }
        if ($this->hasRequestError($run->result)) {
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
