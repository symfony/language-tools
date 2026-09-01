<?php

namespace Symfony\Lsp\Check;

use Amp\DeferredCancellation;

final class CheckRunner
{
    public function __construct(
        private readonly CheckPlanFactory $plans,
        private readonly CheckProjectAnalyzer $projects,
        private readonly CheckDiagnosticExecutor $diagnostics,
        private readonly CheckResultBuilder $results,
        private readonly CheckErrorFactory $errors,
    ) {
    }

    public function run(CheckOptions $options): CheckResult
    {
        if (!\function_exists('symfony_lsp_tree_sitter_parse')) {
            throw new CheckOperationalException('The Symfony Language Tools Tree-sitter parser is unavailable. Run: composer tree-sitter:build');
        }

        $deadline = microtime(true) + $options->timeout;
        $cancellation = new CheckRunCancellation($deadline, $options->timeout);
        $plan = $this->plans->create($options, $deadline);
        $analysis = new CheckProjectAnalysis([], [], [], [], [], [], false);
        $execution = new CheckDiagnosticExecution([], [], [], false);
        $this->installSignalHandlers($cancellation->signal());

        try {
            $analysis = $this->projects->analyze($plan, $cancellation, $options->verbose);
            if (!$analysis->canceled) {
                $execution = $this->diagnostics->execute($plan, $analysis, $cancellation);
            }
        } catch (\Throwable $error) {
            $execution = new CheckDiagnosticExecution(
                $execution->diagnostics,
                [...$execution->errors, $this->errors->internal($error, $plan->workspace)],
                $execution->incompleteProjects,
                false,
            );
        } finally {
            $this->restoreSignalHandlers();
        }

        return $this->results->build($plan, $options, $analysis, $execution, $cancellation);
    }

    private function installSignalHandlers(DeferredCancellation $cancellation): void
    {
        if (!\function_exists('pcntl_async_signals') || !\function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(\SIGINT, static fn () => $cancellation->cancel());
        pcntl_signal(\SIGTERM, static fn () => $cancellation->cancel());
    }

    private function restoreSignalHandlers(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }
        pcntl_signal(\SIGINT, \SIG_DFL);
        pcntl_signal(\SIGTERM, \SIG_DFL);
    }
}
