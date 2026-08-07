<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use Symfony\Lsp\Progress\ProgressReporterInterface;
use Symfony\Lsp\Project\Project;

final class ProgressRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly RuntimeInitializerInterface $initializer,
        private readonly ProgressReporterInterface $progress,
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $token = $this->progress->begin('Symfony runtime index', $project->rootPath());
        $message = 'Runtime index ready';
        try {
            $this->initializer->initialize($project, $plan, $cancellation);
        } catch (CancelledException $error) {
            $message = 'Runtime indexing canceled';

            throw $error;
        } catch (\Throwable $error) {
            $message = 'Runtime indexing failed';

            throw $error;
        } finally {
            $this->progress->end($token, $message);
        }
    }
}
