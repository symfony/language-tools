<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\DocumentStore;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectRegistry;

final class ApplicationSourceScanner
{
    private const EXCLUDED_DIRECTORIES = [
        '.git',
        'node_modules',
        'var',
        'vendor',
    ];

    private const LANGUAGE_IDS = [
        'json' => 'json',
        'php' => 'php',
        'twig' => 'twig',
        'xlf' => 'xml',
        'xliff' => 'xml',
        'yaml' => 'yaml',
        'yml' => 'yaml',
    ];

    /** @var list<SourceIndexProviderInterface> */
    private array $providers;

    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly DocumentStore $documents,
        private readonly ProjectIndexStatusRegistry $statuses,
        SourceIndexProviderInterface ...$providers,
    ) {
        $this->providers = array_values($providers);
    }

    public function indexAll(): void
    {
        foreach ($this->projects->all() as $project) {
            $this->refreshProject($project);
        }
    }

    public function refreshProject(Project $project): void
    {
        $this->statuses->sourceIndexing($project);

        try {
            foreach ($this->providers as $provider) {
                $provider->begin($project);
            }

            foreach ($this->sourceFiles($project->rootPath()) as $path) {
                $text = file_get_contents($path);
                if (false === $text) {
                    continue;
                }

                $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
                $document = new SourceDocument(
                    $this->uri($project, $path),
                    str_starts_with(basename($path), '.env') ? 'dotenv' : self::LANGUAGE_IDS[$extension],
                    $text,
                );
                foreach ($this->providers as $provider) {
                    $provider->index($project, $document);
                }
            }

            foreach ($this->providers as $provider) {
                $provider->finish($project);
            }
            $this->statuses->sourceReady($project);
        } catch (\Throwable $error) {
            $this->statuses->sourceFailed($project, $error);
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function updateOpenDocument(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $document = $this->documents->get($textDocument['uri']);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $document || null === $project) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->overlay($project, $document);
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function restoreClosedDocument(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $project) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->removeOverlay($project, $textDocument['uri']);
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    public function refreshAfterSave(array $params): void
    {
        $textDocument = $params['textDocument'] ?? null;
        if (!\is_array($textDocument) || !\is_string($textDocument['uri'] ?? null)) {
            return;
        }

        $path = parse_url($textDocument['uri'], \PHP_URL_PATH);
        $project = $this->projects->forDocumentUri($textDocument['uri']);
        if (null === $project || !\is_string($path)) {
            return;
        }

        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if (!isset(self::LANGUAGE_IDS[$extension]) && !str_starts_with(basename($path), '.env')) {
            return;
        }

        $this->refreshProject($project);
    }

    /**
     * @return \Generator<int, string>
     */
    private function sourceFiles(string $directory): \Generator
    {
        $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                if (!$file->isLink() && !\in_array($file->getFilename(), self::EXCLUDED_DIRECTORIES, true)) {
                    yield from $this->sourceFiles($file->getPathname());
                }

                continue;
            }

            if ($file->isFile() && (isset(self::LANGUAGE_IDS[strtolower($file->getExtension())]) || str_starts_with($file->getFilename(), '.env'))) {
                yield $file->getPathname();
            }
        }
    }

    private function uri(Project $project, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $project->rootPath()), '/');
        $relativePath = substr(str_replace('\\', '/', $path), \strlen($root) + 1);
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return rtrim($project->rootUri(), '/').'/'.$encodedPath;
    }
}
