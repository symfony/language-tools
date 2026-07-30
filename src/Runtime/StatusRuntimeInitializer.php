<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;

final class StatusRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly RuntimeInitializerInterface $initializer,
        private readonly ProjectIndexStatusRegistry $statuses,
    ) {
    }

    public function initialize(Project $project): void
    {
        $this->statuses->runtimeIndexing($project);

        try {
            $this->initializer->initialize($project);
            $this->statuses->runtimeReady($project);
        } catch (\Throwable $error) {
            $this->statuses->runtimeFailed($project, $error);

            throw $error;
        }
    }
}
