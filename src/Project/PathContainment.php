<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;

final class PathContainment
{
    public static function contains(string $root, string $path, bool $includeRoot = true): bool
    {
        $root = Path::canonicalize($root);
        $path = Path::canonicalize($path);

        return $root === $path ? $includeRoot : Path::isBasePath($root, $path);
    }

    /**
     * Resolves symbolic links in both paths; a path that does not exist yet
     * is resolved through its nearest existing ancestor.
     */
    public static function resolvesInside(string $root, string $path, bool $includeRoot = true): bool
    {
        $realRoot = realpath($root);
        if (false === $realRoot) {
            return self::contains($root, $path, $includeRoot);
        }
        $resolvedPath = self::resolve($path);

        return null !== $resolvedPath && self::contains($realRoot, $resolvedPath, $includeRoot);
    }

    private static function resolve(string $path): ?string
    {
        if (file_exists($path) || is_link($path)) {
            $resolvedPath = realpath($path);

            return false === $resolvedPath ? null : $resolvedPath;
        }

        $parent = \dirname($path);
        while (!file_exists($parent) && !is_link($parent)) {
            $next = \dirname($parent);
            if ($next === $parent) {
                return null;
            }
            $parent = $next;
        }
        $resolvedParent = realpath($parent);

        return false === $resolvedParent ? null : Path::join($resolvedParent, Path::makeRelative($path, $parent));
    }
}
