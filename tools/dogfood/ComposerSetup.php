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

    public function setUp(ProjectConfiguration $configuration, string $applicationRoot): void
    {
        if (!is_file($applicationRoot.'/composer.lock') && null !== $configuration->lockFile) {
            if (false === @copy($configuration->lockFile, $applicationRoot.'/composer.lock')) {
                throw new SetupException(\sprintf('Unable to copy the pinned lock file "%s".', $configuration->lockFile));
            }
        }
        if (!is_file($applicationRoot.'/composer.lock')) {
            throw new SetupException(\sprintf('No composer.lock in "%s"; the dependency set is not reproducible.', $applicationRoot));
        }
        $environment = array_replace($configuration->environmentVariables, ['COMPOSER_NO_INTERACTION' => '1']);
        $manifest = [] === $configuration->allowPlugins ? false : @file_get_contents($applicationRoot.'/composer.json');
        foreach ($configuration->allowPlugins as $plugin) {
            $allow = $this->processes->run(['composer', 'config', '--no-plugins', '--no-interaction', 'allow-plugins.'.$plugin, 'true'], $applicationRoot, $this->timeout, $environment);
            if (!$allow->successful()) {
                throw new SetupException(\sprintf('Unable to allow the Composer plugin "%s".', $plugin));
            }
        }
        $command = ['composer', 'install', '--no-interaction', '--no-progress'];
        foreach ($configuration->ignorePlatformRequirements as $requirement) {
            $command[] = '--ignore-platform-req='.$requirement;
        }
        if (!$this->scripts) {
            $command[] = '--no-scripts';
        }
        $result = $this->processes->run($command, $applicationRoot, $this->timeout, $environment);
        if (false !== $manifest) {
            file_put_contents($applicationRoot.'/composer.json', $manifest);
        }
        if (!$result->successful()) {
            throw new SetupException(\sprintf('composer install failed in "%s": %s', $applicationRoot, trim($result->errorOutput) ?: 'exit code '.$result->exitCode));
        }
    }
}
