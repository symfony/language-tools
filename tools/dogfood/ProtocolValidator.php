<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Component\Filesystem\Path;

final class ProtocolValidator
{
    private const RENAME_PROTECTED_DIRECTORIES = ['vendor/', 'var/'];

    /**
     * @return list<string> violation messages
     */
    public function validate(string $method, mixed $result, string $projectRoot): array
    {
        $projectRoot = Path::canonicalize($projectRoot);
        $violations = [];
        $this->walk($result, $projectRoot, $violations);
        if ('textDocument/rename' === $method && \is_array($result)) {
            foreach ($this->renameTargets($result) as $uri) {
                $path = $this->path($uri);
                if (null === $path || !$this->isProjectPath($path, $projectRoot)) {
                    $violations[] = \sprintf('Rename edits location "%s", which is outside the application.', $uri);
                    continue;
                }
                $relativePath = Path::makeRelative($path, $projectRoot);
                foreach (self::RENAME_PROTECTED_DIRECTORIES as $directory) {
                    if (str_starts_with($relativePath, $directory)) {
                        $violations[] = \sprintf('Rename edits "%s", which is dependency-owned or generated.', $relativePath);
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $violations
     */
    private function walk(mixed $value, string $projectRoot, array &$violations): void
    {
        if (!\is_array($value)) {
            return;
        }
        $range = $value['range'] ?? null;
        if (\is_array($range) && !$this->isValidRange($range)) {
            $violations[] = \sprintf('Invalid range %s.', json_encode($range));
        }
        foreach (['uri', 'target', 'targetUri'] as $key) {
            $uri = $value[$key] ?? null;
            if (\is_string($uri) && str_starts_with($uri, 'file://')) {
                $path = $this->path($uri);
                if (null === $path || !$this->isProjectPath($path, $projectRoot)) {
                    $violations[] = \sprintf('Location "%s" is outside the application.', $uri);
                }
            }
        }
        foreach ($value as $item) {
            $this->walk($item, $projectRoot, $violations);
        }
    }

    /**
     * @param array<array-key, mixed> $edit
     *
     * @return list<string>
     */
    private function renameTargets(array $edit): array
    {
        $uris = [];
        foreach (\is_array($edit['changes'] ?? null) ? array_keys($edit['changes']) : [] as $uri) {
            if (\is_string($uri)) {
                $uris[] = $uri;
            }
        }
        foreach (\is_array($edit['documentChanges'] ?? null) ? $edit['documentChanges'] : [] as $change) {
            $uri = \is_array($change) && \is_array($change['textDocument'] ?? null) ? ($change['textDocument']['uri'] ?? null) : null;
            if (\is_string($uri)) {
                $uris[] = $uri;
            }
        }

        return $uris;
    }

    /**
     * @param array<array-key, mixed> $range
     */
    private function isValidRange(array $range): bool
    {
        $start = $range['start'] ?? null;
        $end = $range['end'] ?? null;
        if (!$this->isValidPosition($start) || !$this->isValidPosition($end)) {
            return false;
        }

        return $start['line'] < $end['line'] || ($start['line'] === $end['line'] && $start['character'] <= $end['character']);
    }

    /**
     * @phpstan-assert-if-true array{line: int, character: int} $position
     */
    private function isValidPosition(mixed $position): bool
    {
        return \is_array($position)
            && \is_int($position['line'] ?? null) && $position['line'] >= 0
            && \is_int($position['character'] ?? null) && $position['character'] >= 0;
    }

    private function path(string $uri): ?string
    {
        if (!str_starts_with($uri, 'file://')) {
            return null;
        }

        $path = rawurldecode(substr($uri, \strlen('file://')));
        if (preg_match('{^/[A-Za-z]:/}', $path)) {
            $path = substr($path, 1);
        }

        return Path::canonicalize(str_replace('\\', '/', $path));
    }

    private function isProjectPath(string $path, string $projectRoot): bool
    {
        return $path === $projectRoot || Path::isBasePath($projectRoot, $path);
    }
}
