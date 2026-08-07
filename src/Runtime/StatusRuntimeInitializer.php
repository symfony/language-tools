<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;

final class StatusRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly RuntimeInitializerInterface $initializer,
        private readonly ProjectIndexStatusRegistry $statuses,
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $this->statuses->runtimeIndexing($project);

        try {
            $this->initializer->initialize($project, $plan, $cancellation);
            $this->statuses->runtimeReady($project);
        } catch (CancelledException $error) {
            $this->statuses->runtimeStale($project);

            throw $error;
        } catch (\Throwable $error) {
            $this->statuses->runtimeFailed($project, $error);

            throw $error;
        }
    }
}
