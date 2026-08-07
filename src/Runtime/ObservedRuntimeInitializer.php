<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Symfony\Lsp\Project\Project;

final class ObservedRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly RuntimeInitializerInterface $initializer,
        private readonly RuntimeRefreshObserverInterface $observer,
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $this->initializer->initialize($project, $plan, $cancellation);
        $this->observer->refreshed($project);
    }
}
