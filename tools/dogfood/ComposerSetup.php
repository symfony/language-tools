<?php

namespace Symfony\Lsp\Tools\Dogfood;

final class ComposerSetup implements SetupInterface
{
    public function __construct(
        private ProcessRunnerInterface $processes,
        private float $timeout = 1800.0,
        private bool $scripts = true,
    ) {
    }

    public function setUp(string $applicationRoot): void
    {
        if (!is_file($applicationRoot.'/composer.lock')) {
            throw new SetupException(\sprintf('No composer.lock in "%s"; the dependency set is not reproducible.', $applicationRoot));
        }
        $command = ['composer', 'install', '--no-interaction', '--no-progress'];
        if (!$this->scripts) {
            $command[] = '--no-scripts';
        }
        $result = $this->processes->run($command, $applicationRoot, $this->timeout);
        if (!$result->successful()) {
            throw new SetupException(\sprintf('composer install failed in "%s": %s', $applicationRoot, trim($result->errorOutput) ?: 'exit code '.$result->exitCode));
        }
    }
}
