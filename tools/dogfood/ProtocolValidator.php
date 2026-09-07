<?php

namespace Symfony\Lsp\Tools\Dogfood;

use Symfony\Component\Filesystem\Path;

/**
 * Checks that an answer only points at real application locations: every file
 * it names must exist and stay inside the project, every range must fit the
 * document it belongs to, and every edit must target a file the application
 * owns. Open documents are matched against their unsaved text.
 */
final class ProtocolValidator
{
    private const PROTECTED_DIRECTORIES = ['vendor/', 'var/'];
    private const RESOURCE_OPERATION_URIS = ['uri', 'oldUri', 'newUri'];

    private string $method = '';
    private string $projectRoot = '';
    private ?string $realProjectRoot = null;

    /** @var array<string, string> */
    private array $openTexts = [];

    /** @var array<string, list<int>|null> */
    private array $lineLengths = [];

    /** @var list<string> */
    private array $violations = [];

    /**
     * @param array<string, string> $openTexts   unsaved document texts, keyed by URI
     * @param string|null           $documentUri the requested document, owning every range that names no file
     *
     * @return list<string> violation messages
     */
    public function validate(string $method, mixed $result, string $projectRoot, array $openTexts = [], ?string $documentUri = null): array
    {
        $this->method = str_starts_with($method, 'textDocument/') ? substr($method, \strlen('textDocument/')) : $method;
        $this->projectRoot = Path::canonicalize($projectRoot);
        $this->realProjectRoot = false === ($real = realpath($this->projectRoot)) ? null : $real;
        $this->openTexts = [];
        foreach ($openTexts as $uri => $text) {
            if (null !== $path = $this->path($uri)) {
                $this->openTexts[$path] = $text;
            }
        }
        $this->lineLengths = [];
        $this->violations = [];
        $this->inspect($result, null === $documentUri ? null : $this->path($documentUri));

        return array_values(array_unique($this->violations));
    }

    /**
     * Ranges belong to the file their own answer names, or to the requested
     * document when the answer names none.
     */
    private function inspect(mixed $value, ?string $documentPath): void
    {
        if (!\is_array($value)) {
            return;
        }
        $handled = [];
        if (\is_array($value['changes'] ?? null) || \is_array($value['documentChanges'] ?? null)) {
            $this->workspaceEdit($value);
            $handled['changes'] = true;
            $handled['documentChanges'] = true;
        }
        $rangePath = $documentPath;
        if (\is_string($value['targetUri'] ?? null)) {
            $targetPath = $this->target($value['targetUri']);
            foreach (['targetRange', 'targetSelectionRange'] as $key) {
                if (\array_key_exists($key, $value)) {
                    $this->range($value[$key], $targetPath);
                    $handled[$key] = true;
                }
            }
            if (\array_key_exists('originSelectionRange', $value)) {
                $this->range($value['originSelectionRange'], $documentPath);
                $handled['originSelectionRange'] = true;
            }
            $handled['targetUri'] = true;
        } elseif (\is_string($value['target'] ?? null)) {
            $this->target($value['target'], allowDirectory: true);
            $handled['target'] = true;
        } elseif (\is_string($value['uri'] ?? null)) {
            $rangePath = $this->target($value['uri']);
            $handled['uri'] = true;
        }
        if (\array_key_exists('range', $value)) {
            $this->range($value['range'], $rangePath);
            $handled['range'] = true;
        }
        foreach ($value as $key => $item) {
            if (!isset($handled[$key])) {
                $this->inspect($item, $rangePath);
            }
        }
    }

    /** @param array<array-key, mixed> $edit */
    private function workspaceEdit(array $edit): void
    {
        foreach (\is_array($edit['changes'] ?? null) ? $edit['changes'] : [] as $uri => $edits) {
            $this->textEdits((string) $uri, $edits);
        }
        foreach (\is_array($edit['documentChanges'] ?? null) ? $edit['documentChanges'] : [] as $change) {
            if (!\is_array($change)) {
                continue;
            }
            if (\is_string($change['kind'] ?? null)) {
                foreach (self::RESOURCE_OPERATION_URIS as $key) {
                    if (\is_string($change[$key] ?? null)) {
                        $this->ownedPath($change[$key]);
                    }
                }
                continue;
            }
            $document = $change['textDocument'] ?? null;
            $uri = \is_array($document) ? ($document['uri'] ?? null) : null;
            if (\is_string($uri)) {
                $this->textEdits($uri, $change['edits'] ?? null);
            }
        }
    }

    private function textEdits(string $uri, mixed $edits): void
    {
        if (null === $this->ownedPath($uri)) {
            return;
        }
        $path = $this->target($uri);
        foreach (\is_array($edits) ? $edits : [] as $edit) {
            if (\is_array($edit) && \array_key_exists('range', $edit)) {
                $this->range($edit['range'], $path);
            }
        }
    }

    /**
     * Reports edits the application does not own, and returns the edited path
     * when it does.
     */
    private function ownedPath(string $uri): ?string
    {
        if (!str_starts_with($uri, 'file://')) {
            return null;
        }
        $path = $this->path($uri);
        if (null === $path || !$this->isProjectPath($path)) {
            $this->violations[] = \sprintf('Location "%s" is outside the application.', $uri);

            return null;
        }
        $relativePath = $this->relativePath($path);
        foreach (self::PROTECTED_DIRECTORIES as $directory) {
            if (str_starts_with($relativePath, $directory)) {
                $this->violations[] = \sprintf('The %s result edits "%s", which is dependency-owned or generated.', $this->method, $relativePath);

                return null;
            }
        }

        return $path;
    }

    /**
     * Reports locations the application does not contain, and returns the path
     * whose text bounds the ranges of that answer.
     */
    private function target(string $uri, bool $allowDirectory = false): ?string
    {
        if (!str_starts_with($uri, 'file://')) {
            return null;
        }
        $path = $this->path($uri);
        if (null === $path || !$this->isProjectPath($path)) {
            $this->violations[] = \sprintf('Location "%s" is outside the application.', $uri);

            return null;
        }
        if (!$this->isRealProjectPath($path)) {
            $this->violations[] = \sprintf('Location "%s" escapes the application through a symbolic link.', $this->relativePath($path));

            return null;
        }
        if (isset($this->openTexts[$path])) {
            return $path;
        }
        if (is_dir($path)) {
            if (!$allowDirectory) {
                $this->violations[] = \sprintf('Location "%s" is a directory.', $this->relativePath($path));
            }

            return null;
        }
        if (!is_file($path)) {
            $this->violations[] = \sprintf('Location "%s" does not exist.', $this->relativePath($path));

            return null;
        }

        return $path;
    }

    private function range(mixed $range, ?string $path): void
    {
        if (!\is_array($range) || !$this->isValidRange($range)) {
            $this->violations[] = \sprintf('Invalid range %s.', json_encode($range));

            return;
        }
        if (null === $path || null === $lineLengths = $this->lineLengths($path)) {
            return;
        }
        foreach (['start' => $range['start'], 'end' => $range['end']] as $edge => $position) {
            if (!isset($lineLengths[$position['line']])) {
                $this->violations[] = \sprintf('Range %s %d:%d is outside "%s", which has %d line(s).', $edge, $position['line'], $position['character'], $this->relativePath($path), \count($lineLengths));
            } elseif ($position['character'] > $lineLengths[$position['line']]) {
                $this->violations[] = \sprintf('Range %s %d:%d is outside "%s", where line %d is %d UTF-16 code unit(s) long.', $edge, $position['line'], $position['character'], $this->relativePath($path), $position['line'], $lineLengths[$position['line']]);
            }
        }
    }

    /** @return list<int>|null */
    private function lineLengths(string $path): ?array
    {
        if (\array_key_exists($path, $this->lineLengths)) {
            return $this->lineLengths[$path];
        }
        $text = $this->openTexts[$path] ?? (is_file($path) ? file_get_contents($path) : false);
        if (!\is_string($text)) {
            return $this->lineLengths[$path] = null;
        }
        $lineLengths = [];
        foreach (explode("\n", $text) as $line) {
            $lineLengths[] = $this->utf16Length(str_ends_with($line, "\r") ? substr($line, 0, -1) : $line);
        }

        return $this->lineLengths[$path] = $lineLengths;
    }

    private function utf16Length(string $line): int
    {
        $bytes = \strlen($line);
        if ($bytes === mb_strlen($line, 'UTF-8')) {
            return $bytes;
        }

        return \strlen(mb_convert_encoding($line, 'UTF-16LE', 'UTF-8')) >> 1;
    }

    /**
     * @param array<array-key, mixed> $range
     *
     * @phpstan-assert-if-true array{start: array{line: int, character: int}, end: array{line: int, character: int}} $range
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
        $path = rawurldecode(substr(explode('#', $uri, 2)[0], \strlen('file://')));
        if (preg_match('{^/[A-Za-z]:/}', $path)) {
            $path = substr($path, 1);
        }

        return Path::canonicalize(str_replace('\\', '/', $path));
    }

    private function isProjectPath(string $path): bool
    {
        return $path === $this->projectRoot || Path::isBasePath($this->projectRoot, $path);
    }

    /**
     * Lexically contained paths can still leave the application through a
     * symbolic link, so the deepest existing ancestor is resolved.
     */
    private function isRealProjectPath(string $path): bool
    {
        if (null === $this->realProjectRoot) {
            return true;
        }
        $candidate = $path;
        while (false === $resolved = realpath($candidate)) {
            $parent = Path::getDirectory($candidate);
            if ('' === $parent || $parent === $candidate) {
                return true;
            }
            $candidate = $parent;
        }

        return $resolved === $this->realProjectRoot || Path::isBasePath($this->realProjectRoot, $resolved);
    }

    private function relativePath(string $path): string
    {
        return $path === $this->projectRoot ? '.' : Path::makeRelative($path, $this->projectRoot);
    }
}
