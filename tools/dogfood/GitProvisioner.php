<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class GitProvisioner implements ProvisionerInterface
{
    public function __construct(
        private ProcessRunnerInterface $processes,
        private Filesystem $filesystem,
        private string $mirrorsDirectory,
        private string $checkoutsDirectory,
        private ?string $repositoryBase = null,
    ) {
    }

    public function provision(ProjectConfiguration $configuration): string
    {
        $mirror = $this->mirror($configuration);
        $checkout = Path::join($this->checkoutsDirectory, $configuration->name);
        $this->filesystem->remove($checkout);
        $this->filesystem->mkdir($this->checkoutsDirectory);
        $this->git(['clone', '--no-checkout', '--', $mirror, $checkout], \sprintf('Unable to clone "%s".', $configuration->name));
        $this->git(['-C', $checkout, '-c', 'advice.detachedHead=false', 'checkout', '--detach', $configuration->revision], \sprintf('Unable to check out revision "%s" of "%s".', $configuration->revision, $configuration->name));

        return $checkout;
    }

    public function release(ProjectConfiguration $configuration): void
    {
        $this->filesystem->remove(Path::join($this->checkoutsDirectory, $configuration->name));
    }

    private function mirror(ProjectConfiguration $configuration): string
    {
        $repository = $this->resolveRepository($configuration);
        $mirror = Path::join($this->mirrorsDirectory, $configuration->name.'.git');
        if (!is_dir($mirror)) {
            $this->filesystem->mkdir($this->mirrorsDirectory);
            $this->git(['clone', '--mirror', '--', $repository, $mirror], \sprintf('Unable to mirror "%s".', $repository));
        }
        if ($this->hasRevision($mirror, $configuration->revision)) {
            return $mirror;
        }
        $this->git(['-C', $mirror, 'remote', 'update', '--prune'], \sprintf('Unable to update the mirror of "%s".', $repository));
        if (!$this->hasRevision($mirror, $configuration->revision)) {
            throw new ProvisioningException(\sprintf('Revision "%s" does not exist in "%s".', $configuration->revision, $repository));
        }

        return $mirror;
    }

    private function resolveRepository(ProjectConfiguration $configuration): string
    {
        if (1 === preg_match('{^(?:[a-z+]+://|[^/]+@|[A-Za-z0-9.-]+:)}', $configuration->repository)) {
            return $configuration->repository;
        }
        if (null === $this->repositoryBase) {
            throw new ProvisioningException(\sprintf('Project "%s" uses the relative repository "%s"; pass --repository-base.', $configuration->name, $configuration->repository));
        }
        $repository = Path::join($this->repositoryBase, $configuration->repository);
        if (!is_dir($repository)) {
            throw new ProvisioningException(\sprintf('Repository "%s" does not exist.', $repository));
        }

        return $repository;
    }

    private function hasRevision(string $mirror, string $revision): bool
    {
        return $this->processes->run(['git', '-C', $mirror, 'cat-file', '-e', $revision.'^{commit}'])->successful();
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments, string $message): void
    {
        $result = $this->processes->run(['git', ...$arguments]);
        if (!$result->successful()) {
            throw new ProvisioningException(trim($message.' '.trim($result->errorOutput)));
        }
    }
}
