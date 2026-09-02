<?php

namespace Symfony\Lsp\Check;

final class BaselineManager
{
    public function __construct(
        private readonly BaselineRepository $repository,
        private readonly BaselineMatcher $matcher,
    ) {
    }

    /**
     * @param list<CheckDiagnostic> $diagnostics
     *
     * @return array{diagnostics: list<CheckDiagnostic>, stale: list<BaselineEntry>, path: string|null}
     */
    public function apply(string $workspace, CheckOptions $options, array $diagnostics, bool $complete = true): array
    {
        if (null === $options->baselinePath) {
            return ['diagnostics' => $diagnostics, 'stale' => [], 'path' => null];
        }

        $file = $this->repository->resolve($workspace, $options->baselinePath);
        if (!$complete && !$this->repository->exists($file)) {
            return ['diagnostics' => $diagnostics, 'stale' => [], 'path' => null];
        }
        if (!$complete || 'none' === $options->baselineMode) {
            $entries = $this->repository->load($file);
        } else {
            $entries = $this->matcher->entries($diagnostics);
            if ('create' === $options->baselineMode) {
                $this->repository->create($file, $entries);
            } else {
                $this->repository->refresh($file, $entries);
            }
        }

        $result = $this->matcher->match($diagnostics, $entries);

        return [
            'diagnostics' => $result['diagnostics'],
            'stale' => $complete ? $result['stale'] : [],
            'path' => $file->workspacePath,
        ];
    }
}
