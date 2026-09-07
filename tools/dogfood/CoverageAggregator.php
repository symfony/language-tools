<?php

namespace Symfony\Lsp\Tools\Dogfood;

/**
 * Unions the per-process coverage artifacts of a dogfooding session, so cold
 * runs, warm runs and every project add up to a single execution picture.
 *
 * Artifacts are rejected instead of skipped when they are malformed, record
 * files outside the measured source tree, or carry a source identity other
 * than the expected one: a partial union would silently understate what the
 * runs reached, and a mixed union would place stale line numbers in files that
 * changed since.
 */
final class CoverageAggregator
{
    public const FORMAT = 'symfony-lsp-coverage/2';

    public function __construct(private readonly string $sourcePrefix = 'src/')
    {
    }

    /**
     * @param iterable<string, string> $artifacts              JSON documents keyed by artifact name
     * @param list<string>             $sourceFiles            every measurable file, relative to the repository root
     * @param string|null              $expectedSourceIdentity identity of the source tree the report must describe
     */
    public function aggregate(iterable $artifacts, array $sourceFiles = [], ?string $expectedSourceIdentity = null): CoverageReport
    {
        /** @var array<string, array<int, bool>> $executed */
        $executed = [];
        /** @var array<string, array<int, bool>> $unexecuted */
        $unexecuted = [];
        /** @var array<string, array<string, array{line: int, hit: bool}>> $branches */
        $branches = [];
        $artifactCount = 0;
        $identity = $expectedSourceIdentity;
        $identityOrigin = null;
        foreach ($artifacts as $name => $document) {
            ++$artifactCount;
            $artifact = $this->read((string) $name, $document);
            if (null === $identity) {
                $identity = $artifact['source'];
                $identityOrigin = (string) $name;
            } elseif ($identity !== $artifact['source']) {
                if (null === $identityOrigin) {
                    throw new CoverageException(\sprintf('Coverage artifact "%s" measured source %s, but the current source tree is %s; the source changed, so rerun the matrix.', $name, $artifact['source'], $identity));
                }

                throw new CoverageException(\sprintf('Coverage artifacts "%s" and "%s" measured different source trees (%s and %s); drop the stale artifacts and rerun the matrix.', $identityOrigin, $name, $identity, $artifact['source']));
            }
            foreach ($artifact['files'] as $path => $file) {
                $executed[$path] ??= [];
                $unexecuted[$path] ??= [];
                $branches[$path] ??= [];
                foreach ($file['executed'] as $line) {
                    $executed[$path][$line] = true;
                }
                foreach ($file['unexecuted'] as $line) {
                    $unexecuted[$path][$line] = true;
                }
                foreach ($file['branches'] as $key => $branch) {
                    $branches[$path][$key] = ['line' => $branch['line'], 'hit' => $branch['hit'] || ($branches[$path][$key]['hit'] ?? false)];
                }
            }
        }
        foreach ($sourceFiles as $path) {
            $this->checkPath('the source file list', $path);
            $executed[$path] ??= [];
            $unexecuted[$path] ??= [];
            $branches[$path] ??= [];
        }

        $files = [];
        $paths = array_keys($executed);
        sort($paths, \SORT_STRING);
        foreach ($paths as $path) {
            $executedLines = array_keys($executed[$path]);
            sort($executedLines);
            $unexecutedLines = array_keys(array_diff_key($unexecuted[$path], $executed[$path]));
            sort($unexecutedLines);
            $unhitBranchLines = [];
            $hitBranchCount = 0;
            foreach ($branches[$path] as $branch) {
                if ($branch['hit']) {
                    ++$hitBranchCount;
                } else {
                    $unhitBranchLines[$branch['line']] = true;
                }
            }
            $unhitBranchLines = array_keys($unhitBranchLines);
            sort($unhitBranchLines);
            $files[$path] = new CoverageFile($path, $executedLines, $unexecutedLines, \count($branches[$path]), $hitBranchCount, $unhitBranchLines);
        }

        return new CoverageReport($files, $artifactCount, $identity);
    }

    /**
     * @return array{source: string, files: array<string, array{executed: list<int>, unexecuted: list<int>, branches: array<string, array{line: int, hit: bool}>}>}
     */
    private function read(string $name, string $document): array
    {
        try {
            $decoded = json_decode($document, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new CoverageException(\sprintf('Coverage artifact "%s" is not valid JSON: %s', $name, $e->getMessage()));
        }
        if (!\is_array($decoded) || self::FORMAT !== ($decoded['format'] ?? null)) {
            throw new CoverageException(\sprintf('Coverage artifact "%s" is not in the "%s" format.', $name, self::FORMAT));
        }
        $source = $decoded['source'] ?? null;
        if (!\is_string($source) || 1 !== preg_match('/^sha256:[0-9a-f]{64}$/D', $source)) {
            throw new CoverageException(\sprintf('Coverage artifact "%s" does not carry a source identity.', $name));
        }
        if (!\is_array($decoded['files'] ?? null)) {
            throw new CoverageException(\sprintf('Coverage artifact "%s" does not contain a "files" map.', $name));
        }

        $files = [];
        foreach ($decoded['files'] as $path => $file) {
            if (!\is_string($path)) {
                throw new CoverageException(\sprintf('Coverage artifact "%s" contains a non-string file path.', $name));
            }
            $this->checkPath(\sprintf('coverage artifact "%s"', $name), $path);
            if (!\is_array($file)) {
                throw new CoverageException(\sprintf('Coverage artifact "%s" contains invalid data for "%s".', $name, $path));
            }
            $files[$path] = [
                'executed' => $this->lines($name, $path, 'executed', $file['executed'] ?? null),
                'unexecuted' => $this->lines($name, $path, 'unexecuted', $file['unexecuted'] ?? null),
                'branches' => $this->branches($name, $path, $file['branches'] ?? []),
            ];
        }

        return ['source' => $source, 'files' => $files];
    }

    /**
     * @return list<int>
     */
    private function lines(string $name, string $path, string $key, mixed $lines): array
    {
        if (!\is_array($lines) || !array_is_list($lines)) {
            throw new CoverageException(\sprintf('Coverage artifact "%s" does not list "%s" lines for "%s".', $name, $key, $path));
        }
        $numbers = [];
        foreach ($lines as $line) {
            if (!\is_int($line) || 1 > $line) {
                throw new CoverageException(\sprintf('Coverage artifact "%s" contains an invalid "%s" line number for "%s".', $name, $key, $path));
            }
            $numbers[] = $line;
        }

        return $numbers;
    }

    /**
     * @return array<string, array{line: int, hit: bool}>
     */
    private function branches(string $name, string $path, mixed $branches): array
    {
        if (!\is_array($branches) || !array_is_list($branches)) {
            throw new CoverageException(\sprintf('Coverage artifact "%s" does not list branches for "%s".', $name, $path));
        }
        $entries = [];
        foreach ($branches as $branch) {
            if (!\is_array($branch)
                || !\is_string($branch['function'] ?? null)
                || !\is_int($branch['op'] ?? null)
                || !\is_int($branch['line'] ?? null)
                || 1 > $branch['line']
                || !\is_bool($branch['hit'] ?? null)
            ) {
                throw new CoverageException(\sprintf('Coverage artifact "%s" contains an invalid branch entry for "%s".', $name, $path));
            }
            $entries[$branch['function'].'#'.$branch['op'].'#'.$branch['line']] = ['line' => $branch['line'], 'hit' => $branch['hit']];
        }

        return $entries;
    }

    private function checkPath(string $origin, string $path): void
    {
        if (!str_starts_with($path, $this->sourcePrefix) || str_contains($path, '..') || !str_ends_with($path, '.php')) {
            throw new CoverageException(\sprintf('The path "%s" from %s is not a PHP file below "%s".', $path, $origin, $this->sourcePrefix));
        }
    }
}
