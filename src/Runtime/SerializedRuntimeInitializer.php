<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Amp\Sync\KeyedMutex;
use Symfony\Lsp\Project\Project;

final class SerializedRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly RuntimeInitializerInterface $initializer,
        private readonly KeyedMutex $mutex,
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $lock = $this->mutex->acquire($project->rootPath());
        try {
            $cancellation?->throwIfRequested();
            $this->initializer->initialize($project, $plan, $cancellation);
        } finally {
            $lock->release();
        }
    }
}
