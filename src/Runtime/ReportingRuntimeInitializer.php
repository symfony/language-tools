<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Index\ProjectIndexStatusRegistry;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Server\ServerLogger;

final class ReportingRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly RuntimeInitializerInterface $initializer,
        private readonly ClientInterface $client,
        private readonly ProjectIndexStatusRegistry $statuses,
        private readonly ServerLogger $logger,
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        try {
            $this->initializer->initialize($project, $plan, $cancellation);
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->logger->error($error);
            $runtimeStatus = $this->statuses->status($project)['runtime'];
            $stale = 'stale' === $runtimeStatus['state'];
            $message = 'configuration' === ($runtimeStatus['stage'] ?? null)
                ? \sprintf(
                    $stale
                        ? 'Symfony Language Tools found invalid application configuration for "%s". The last valid runtime metadata remains active.'
                        : 'Symfony Language Tools found invalid application configuration for "%s".',
                    $project->rootPath(),
                )
                : \sprintf(
                    $stale
                        ? 'Symfony Language Tools could not refresh runtime metadata for "%s". The last valid metadata remains active.'
                        : 'Symfony Language Tools could not initialize runtime metadata for "%s". Static-only features remain active.',
                    $project->rootPath(),
                );
            $this->client->notify('window/showMessage', [
                'type' => 1,
                'message' => $message,
            ]);
        }
    }
}
