<?php

namespace Symfony\Lsp\Check;

use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\InvalidConfigurationException;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectConfiguration;
use Symfony\Lsp\Runtime\RuntimeConfiguration;

final class CheckResultBuilder
{
    public function __construct(
        private readonly BaselineManager $baseline,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly ProjectConfiguration $projectConfiguration,
        private readonly RuntimeConfiguration $runtimeConfiguration,
        private readonly CheckErrorFactory $errors,
        private readonly string $version,
    ) {
    }

    public function build(
        CheckPlan $plan,
        CheckOptions $options,
        CheckProjectAnalysis $analysis,
        CheckDiagnosticExecution $execution,
        CheckRunCancellation $cancellation,
    ): CheckResult {
        $errors = [...$analysis->errors, ...$execution->errors];
        if ($analysis->canceled || $execution->canceled) {
            $errors[] = $this->errors->cancellation($cancellation, $plan->workspace);
        }

        $projects = [];
        foreach ($plan->projects as $root => $project) {
            $complete = true === ($analysis->completeProjects[$root] ?? false)
                && !isset($execution->incompleteProjects[$root]);
            $projects[] = $this->projectResult(
                $project,
                $analysis->statuses[$root] ?? $this->statuses->status($project),
                $complete,
            );
        }
        usort($projects, static fn (CheckProjectResult $left, CheckProjectResult $right): int => strcmp($left->id, $right->id));

        $diagnostics = $execution->diagnostics;
        usort($diagnostics, static fn (CheckDiagnostic $left, CheckDiagnostic $right): int => [
            $left->project,
            $left->path,
            $left->startLine,
            $left->startCharacter,
            $left->endLine,
            $left->endCharacter,
            $left->code,
            $left->message,
        ] <=> [
            $right->project,
            $right->path,
            $right->startLine,
            $right->startCharacter,
            $right->endLine,
            $right->endCharacter,
            $right->code,
            $right->message,
        ]);

        if ($cancellation->expired() && [] === $errors) {
            $errors[] = $this->errors->timeout($options->timeout, $plan->workspace);
            $projects = $this->incomplete($projects);
        }

        $stale = [];
        $baselinePath = null;
        if ([] === $errors) {
            try {
                $baseline = $this->baseline->apply($plan->workspace, $options, $diagnostics);
                $diagnostics = $baseline['diagnostics'];
                $stale = $baseline['stale'];
                $baselinePath = $baseline['path'];
            } catch (InvalidConfigurationException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $errors[] = $this->errors->internal($error, $plan->workspace);
                $projects = $this->incomplete($projects);
            }
            if ($cancellation->expired() && [] === $errors) {
                $errors[] = $this->errors->timeout($options->timeout, $plan->workspace);
                $projects = $this->incomplete($projects);
            }
        }
        $blockingCount = $this->blockingCount($diagnostics, $options->blockingCodes)
            + ($options->strictBaseline ? \count($stale) : 0);

        return new CheckResult(
            $this->version,
            [] === $errors,
            $projects,
            $diagnostics,
            $stale,
            $baselinePath,
            $options->baselineMode,
            $options->strictBaseline,
            $errors,
            $blockingCount,
        );
    }

    /**
     * @param array{root: string, source: array{state: string, error?: string}, runtime: array{state: string, error?: string, stage?: string, lastSuccessfulAt?: string}} $status
     */
    private function projectResult(Project $project, array $status, bool $complete): CheckProjectResult
    {
        $reason = $this->runtimeConfiguration->sourceOnlyReason($project);
        if (null !== $reason) {
            $status['runtime'] = ['state' => 'disabled', 'reason' => $reason];
        }

        return new CheckProjectResult(
            $this->projectConfiguration->projectId($project),
            $this->runtimeConfiguration->environment($project),
            null === $reason ? 'runtime' : 'source-only',
            $reason,
            $status['source'],
            $status['runtime'],
            $complete,
        );
    }

    /**
     * @param list<CheckDiagnostic> $diagnostics
     * @param list<string>|null     $blockingCodes
     */
    private function blockingCount(array $diagnostics, ?array $blockingCodes): int
    {
        return \count(array_filter($diagnostics, static function (CheckDiagnostic $diagnostic) use ($blockingCodes): bool {
            if ('matched' === $diagnostic->baselineState) {
                return false;
            }

            return null === $blockingCodes
                ? 1 === $diagnostic->severity
                : \in_array($diagnostic->code, $blockingCodes, true);
        }));
    }

    /**
     * @param list<CheckProjectResult> $projects
     *
     * @return list<CheckProjectResult>
     */
    private function incomplete(array $projects): array
    {
        return array_map(
            static fn (CheckProjectResult $project): CheckProjectResult => $project->withComplete(false),
            $projects,
        );
    }
}
