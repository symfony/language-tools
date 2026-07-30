<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

final class ObservedRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly RuntimeInitializerInterface $initializer,
        private readonly RuntimeRefreshObserverInterface $observer,
    ) {
    }

    public function initialize(Project $project): void
    {
        $this->initializer->initialize($project);
        $this->observer->refreshed($project);
    }
}
