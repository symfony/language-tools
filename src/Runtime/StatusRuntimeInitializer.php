<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class StatusRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly RuntimeInitializerInterface $initializer,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly ProjectRegistry $projects,
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $this->statuses->runtimeIndexing($project);

        try {
            $this->initializer->initialize($project, $plan, $cancellation);
            $this->statuses->runtimeReady($project);
        } catch (CancelledException $error) {
            if ($this->projects->contains($project)) {
                $this->statuses->runtimeStale($project);
            }

            throw $error;
        } catch (\Throwable $error) {
            if ($this->projects->contains($project)) {
                $this->statuses->runtimeFailed($project, $error instanceof BridgeExecutionException ? 'bootstrap' : null);
            }

            throw $error;
        }
    }
}
