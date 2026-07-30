<?php

namespace Symfony\Lsp\Runtime;

use Symfony\Lsp\Project\Project;

final class BridgeInstaller implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly string $bridgeSource,
        private readonly string $serverVersion,
    ) {
    }

    public function initialize(Project $project): void
    {
        $this->install($project);
    }

    public function install(Project $project): string
    {
        $contents = file_get_contents($this->bridgeSource);
        if (false === $contents) {
            throw new \RuntimeException('Unable to read the bundled project bridge.');
        }

        $directory = $project->rootPath().'/var/symfony-lsp/'.$this->serverVersion;
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Unable to create bridge directory "%s".', $directory));
        }

        $destination = $directory.'/bridge.php';
        $destinationHash = is_file($destination) ? hash_file('sha256', $destination) : false;
        if (false !== $destinationHash && hash_equals(hash('sha256', $contents), $destinationHash)) {
            return $destination;
        }

        $temporary = tempnam($directory, 'bridge-');
        if (false === $temporary) {
            throw new \RuntimeException(\sprintf('Unable to create a bridge file in "%s".', $directory));
        }

        try {
            if (false === file_put_contents($temporary, $contents) || !rename($temporary, $destination)) {
                throw new \RuntimeException(\sprintf('Unable to install project bridge "%s".', $destination));
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $destination;
    }
}
