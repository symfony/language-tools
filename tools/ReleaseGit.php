<?php

namespace Symfony\Lsp\Tools;

final class ReleaseGit
{
    public function __construct(private string $root, private ReleaseProcessRunner $processes)
    {
    }

    public function fetchMain(): void
    {
        $this->processes->run(['git', 'fetch', 'origin', 'refs/heads/main:refs/remotes/origin/main'], $this->root);
    }

    public function currentBranch(): string
    {
        return $this->processes->capture(['git', 'branch', '--show-current'], $this->root);
    }

    public function isClean(): bool
    {
        return '' === $this->processes->capture(['git', 'status', '--porcelain=v1', '--untracked-files=no'], $this->root);
    }

    public function revision(string $revision): string
    {
        return $this->processes->capture(['git', 'rev-parse', $revision], $this->root);
    }

    public function subject(): string
    {
        return $this->processes->capture(['git', 'log', '-1', '--format=%s'], $this->root);
    }

    public function remoteTagExists(string $tag): bool
    {
        return $this->processes->succeeds(['git', 'ls-remote', '--exit-code', '--tags', 'origin', 'refs/tags/'.$tag], $this->root);
    }

    public function localTagExists(string $tag): bool
    {
        return $this->processes->succeeds(['git', 'rev-parse', '--verify', '--quiet', 'refs/tags/'.$tag], $this->root);
    }

    public function remoteTagCommit(string $tag): string
    {
        $output = $this->processes->capture(['git', 'ls-remote', '--tags', 'origin', 'refs/tags/'.$tag], $this->root);
        $parts = preg_split('/\s+/', $output);
        if (false === $parts || !isset($parts[0]) || !preg_match('/^[0-9a-f]{40}$/', $parts[0])) {
            throw new \RuntimeException(\sprintf('Unable to resolve remote tag %s.', $tag));
        }

        return $parts[0];
    }

    /** @return list<string> */
    public function changedFiles(): array
    {
        $changed = preg_split('/\R/', $this->processes->capture(['git', 'diff', '--name-only'], $this->root), flags: \PREG_SPLIT_NO_EMPTY);
        if (false === $changed) {
            throw new \RuntimeException('Unable to inspect the release diff.');
        }
        sort($changed);

        return $changed;
    }

    /** @param list<string> $files */
    public function add(array $files): void
    {
        $this->processes->run(['git', 'add', ...$files], $this->root);
    }

    public function commit(string $message): void
    {
        $this->processes->run(['git', 'commit', '-m', $message], $this->root);
    }

    public function pushMain(): void
    {
        $this->processes->run(['git', 'push', 'origin', 'HEAD:refs/heads/main'], $this->root);
    }

    public function tag(string $tag): void
    {
        $this->processes->run(['git', 'tag', $tag], $this->root);
    }

    public function pushTag(string $tag): void
    {
        $this->processes->run(['git', 'push', 'origin', \sprintf('refs/tags/%s:refs/tags/%s', $tag, $tag)], $this->root);
    }
}
