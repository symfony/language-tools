<?php

namespace Symfony\Lsp\Runtime;

use Amp\Cancellation;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Lsp\Project\Project;

final class BridgeInstaller implements RuntimeInitializerInterface
{
    public function __construct(
        private readonly string $bridgeSource,
        private readonly string $serverVersion,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function initialize(Project $project, ?RuntimeRefreshPlan $plan = null, ?Cancellation $cancellation = null): void
    {
        $cancellation?->throwIfRequested();
        $this->install($project);
    }

    public function install(Project $project): string
    {
        $files = $this->bundleFiles();
        $hash = hash('sha256', serialize($files));
        $baseDirectory = Path::join($project->rootPath(), 'var/symfony-lsp', $this->serverVersion);
        $this->filesystem->mkdir($baseDirectory);

        $directory = Path::join($baseDirectory, $hash);
        if ($this->isInstalled($directory, $files)) {
            return Path::join($directory, 'bridge.php');
        }

        $temporary = Path::join($baseDirectory, '.bridge-'.$hash.'-'.bin2hex(random_bytes(8)));
        $this->filesystem->mkdir($temporary);

        try {
            foreach ($files as $relativePath => $contents) {
                $this->filesystem->dumpFile(Path::join($temporary, $relativePath), $contents);
            }
            if (!@rename($temporary, $directory) && !$this->isInstalled($directory, $files)) {
                throw new \RuntimeException(\sprintf('Unable to install project bridge "%s".', $directory));
            }
        } finally {
            $this->filesystem->remove($temporary);
        }

        return Path::join($directory, 'bridge.php');
    }

    /** @return array<string, string> */
    private function bundleFiles(): array
    {
        $contents = file_get_contents($this->bridgeSource);
        if (false === $contents) {
            throw new \RuntimeException('Unable to read the bundled project bridge.');
        }
        $files = ['bridge.php' => $contents];
        $sourceDirectory = Path::join(\dirname($this->bridgeSource), 'bridge');
        if (is_dir($sourceDirectory)) {
            $finder = (new Finder())->files()->in($sourceDirectory)->ignoreDotFiles(false)->ignoreVCS(false);
            foreach ($finder as $file) {
                $contents = file_get_contents($file->getPathname());
                if (false === $contents) {
                    throw new \RuntimeException(\sprintf('Unable to read bundled project bridge file "%s".', $file->getPathname()));
                }
                $relativePath = Path::join('bridge', Path::makeRelative($file->getPathname(), $sourceDirectory));
                $files[$relativePath] = $contents;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param array<string, string> $files
     *
     * @phpstan-impure
     */
    private function isInstalled(string $directory, array $files): bool
    {
        foreach ($files as $relativePath => $contents) {
            $destination = Path::join($directory, $relativePath);
            if (!is_file($destination) || hash('sha256', $contents) !== hash_file('sha256', $destination)) {
                return false;
            }
        }

        return true;
    }
}
