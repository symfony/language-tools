<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;

final class ReportingRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly RuntimeInitializerInterface $initializer,
        private readonly ClientInterface $client,
        private readonly ProjectIndexStatusRegistry $statuses,
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        try {
            $this->initializer->initialize($project, $plan, $cancellation);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            $stale = 'stale' === $this->statuses->status($project)['runtime']['state'];
            $this->client->notify('window/showMessage', [
                'type' => 1,
                'message' => \sprintf(
                    $stale
                        ? 'Symfony LSP could not refresh runtime metadata for "%s". The last valid metadata remains active.'
                        : 'Symfony LSP could not initialize runtime metadata for "%s". Static-only features remain active.',
                    $project->rootPath(),
                ),
            ]);
        }
    }
}
