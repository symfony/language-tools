<?php

namespace Symfony\Lsp\Server;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Client\ClientInterface;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;
use Symfony\Lsp\Project\UriToPathConverter;

final class WorkspaceFileWatcher
{
    private const DIRECTORY_CHANGE_KIND = 1 | 4;
    private const EXCLUDED_DIRECTORIES = ['.git', 'node_modules', 'var', 'vendor'];
    private const SOURCE_PATTERN = '*.{php,twig,yaml,yml,ini,json,xml,xlf,xliff,css,js,mjs,ts,svg,png,jpg,jpeg,gif,webp,woff,woff2,ttf,otf,wasm}';

    private bool $supported = false;
    private bool $relativePatternSupported = false;
    private bool $registered = false;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly ProjectRegistry $projects,
        private readonly UriToPathConverter $uriToPathConverter,
    ) {
    }

    /** @param array<array-key, mixed> $initializeParams */
    public function initialize(array $initializeParams): void
    {
        $capabilities = $initializeParams['capabilities'] ?? null;
        $workspace = \is_array($capabilities) ? ($capabilities['workspace'] ?? null) : null;
        $watchedFiles = \is_array($workspace) ? ($workspace['didChangeWatchedFiles'] ?? null) : null;
        $this->supported = \is_array($watchedFiles) && true === ($watchedFiles['dynamicRegistration'] ?? null);
        $this->relativePatternSupported = \is_array($watchedFiles) && true === ($watchedFiles['relativePatternSupport'] ?? null);
    }

    public function register(): void
    {
        if (!$this->supported || $this->registered) {
            return;
        }

        try {
            $this->client->request('client/registerCapability', [
                'registrations' => [[
                    'id' => 'symfony-lsp-workspace-files',
                    'method' => 'workspace/didChangeWatchedFiles',
                    'registerOptions' => ['watchers' => $this->watchers()],
                ]],
            ]);
            $this->registered = true;
        } catch (\Throwable) {
        }
    }

    public function refresh(): void
    {
        if (!$this->registered) {
            $this->register();

            return;
        }

        try {
            $this->client->request('client/unregisterCapability', [
                'unregisterations' => [[
                    'id' => 'symfony-lsp-workspace-files',
                    'method' => 'workspace/didChangeWatchedFiles',
                ]],
            ]);
            $this->registered = false;
            $this->register();
        } catch (\Throwable) {
        }
    }

    /** @return list<array{globPattern: string|array{baseUri: string, pattern: string}, kind?: int}> */
    private function watchers(): array
    {
        if (!$this->relativePatternSupported) {
            return [
                ['globPattern' => '**/'.self::SOURCE_PATTERN],
                ['globPattern' => '**/.env*'],
                ['globPattern' => '**/.gitignore'],
                ['globPattern' => '**/composer.{json,lock}'],
            ];
        }

        $watchers = [['globPattern' => '**/composer.{json,lock}']];
        foreach ($this->projects->all() as $project) {
            $watchers[] = $this->relative($project, self::SOURCE_PATTERN);
            $watchers[] = $this->relative($project, '.env*');
            $watchers[] = $this->relative($project, '.gitignore');
            $watchers[] = $this->relative($project, 'composer.{json,lock}');
            $watchers[] = $this->relative($project, '*', self::DIRECTORY_CHANGE_KIND);
            foreach ($this->sourceDirectories($project) as $directory) {
                $watchers[] = $this->relative($project, $directory.'/**/'.self::SOURCE_PATTERN);
                $watchers[] = $this->relative($project, $directory.'/**/.gitignore');
                $watchers[] = $this->relative($project, $directory, self::DIRECTORY_CHANGE_KIND);
                $watchers[] = $this->relative($project, $directory.'/**', self::DIRECTORY_CHANGE_KIND);
            }
        }

        return $watchers;
    }

    public function requiresRefreshForChange(string $uri, int $changeType): bool
    {
        if (1 !== $changeType) {
            return false;
        }
        $path = $this->uriToPathConverter->convert($uri);
        if (null === $path || !is_dir($path)) {
            return false;
        }
        $path = Path::canonicalize($path);
        foreach ($this->projects->all() as $project) {
            if (Path::canonicalize($project->rootPath()) === \dirname($path)
                && !\in_array(basename($path), self::EXCLUDED_DIRECTORIES, true)
                && preg_match('/^[A-Za-z0-9_.-]+$/D', basename($path))
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return array{globPattern: array{baseUri: string, pattern: string}, kind?: int} */
    private function relative(Project $project, string $pattern, ?int $kind = null): array
    {
        $watcher = ['globPattern' => ['baseUri' => $project->rootUri(), 'pattern' => $pattern]];
        if (null !== $kind) {
            $watcher['kind'] = $kind;
        }

        return $watcher;
    }

    /** @return list<string> */
    private function sourceDirectories(Project $project): array
    {
        $directories = [];
        foreach (scandir($project->rootPath()) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry || \in_array($entry, self::EXCLUDED_DIRECTORIES, true)) {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_.-]+$/D', $entry) && is_dir($project->rootPath().'/'.$entry)) {
                $directories[] = $entry;
            }
        }
        sort($directories);

        return $directories;
    }
}
