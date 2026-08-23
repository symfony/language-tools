<?php

namespace Symfony\Lsp\Feature\Asset;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Lsp\Project\Project;
use Symfony\Lsp\Project\ProjectStateInterface;

/**
 * Resolves asset() paths against the public/ document root for projects
 * that serve plain files instead of AssetMapper logical paths.
 */
final class PublicAssetResolver implements ProjectStateInterface
{
    private const CACHE_TTL_SECONDS = 10;
    private const MAX_FILES = 5000;

    /** @var array<string, array{int, list<string>}> */
    private array $cache = [];

    public function removeProject(Project $project): void
    {
        unset($this->cache[Path::canonicalize(Path::join($project->rootPath(), 'public'))]);
    }

    public function path(Project $project, string $logicalPath): ?string
    {
        $publicRoot = $this->publicRoot($project);
        if (null === $publicRoot) {
            return null;
        }
        $path = Path::canonicalize(Path::join($publicRoot, $logicalPath));
        if (!Path::isBasePath($publicRoot, $path) || $publicRoot === $path || !is_file($path)) {
            return null;
        }

        return $path;
    }

    /** @return list<string> */
    public function logicalPaths(Project $project): array
    {
        $publicRoot = $this->publicRoot($project);
        if (null === $publicRoot) {
            return [];
        }
        $now = time();
        $cached = $this->cache[$publicRoot] ?? null;
        if (null !== $cached && $now < $cached[0]) {
            return $cached[1];
        }
        $paths = [];
        $files = (new Finder())->files()->in($publicRoot)->ignoreUnreadableDirs()->sortByName();
        foreach ($files as $file) {
            $paths[] = Path::makeRelative(Path::canonicalize($file->getPathname()), $publicRoot);
            if (\count($paths) >= self::MAX_FILES) {
                break;
            }
        }
        $this->cache[$publicRoot] = [$now + self::CACHE_TTL_SECONDS, $paths];

        return $paths;
    }

    private function publicRoot(Project $project): ?string
    {
        $publicRoot = Path::canonicalize(Path::join($project->rootPath(), 'public'));

        return is_dir($publicRoot) ? $publicRoot : null;
    }
}
