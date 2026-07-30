<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Project\Project;

final class ReportingRuntimeInitializer implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly RuntimeInitializerInterface $initializer,
        private readonly ClientInterface $client,
    ) {
    }

    public function initialize(Project $project): void
    {
        try {
            $this->initializer->initialize($project);
        } catch (\Throwable) {
            $this->client->notify('window/showMessage', [
                'type' => 1,
                'message' => \sprintf(
                    'Symfony LSP could not refresh runtime metadata for "%s". The last valid metadata remains active.',
                    $project->rootPath(),
                ),
            ]);
        }
    }
}
