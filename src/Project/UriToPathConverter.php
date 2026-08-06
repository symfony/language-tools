<?php

namespace Symfony\Lsp\Project;

use Symfony\Component\Filesystem\Path;

final class UriToPathConverter
{
    public function convert(string $uri): ?string
    {
        $parts = parse_url($uri);
        if (false === $parts || 'file' !== strtolower($parts['scheme'] ?? '')) {
            return null;
        }

        if (isset($parts['host']) && '' !== $parts['host'] && 'localhost' !== strtolower($parts['host'])) {
            return null;
        }

        $path = rawurldecode($parts['path'] ?? '');
        if ('' === $path) {
            return null;
        }

        if (preg_match('{^/[A-Za-z]:/}', $path)) {
            $path = substr($path, 1);
        }

        return Path::canonicalize(str_replace('\\', '/', $path));
    }

    public function toUri(string $path): string
    {
        $path = Path::canonicalize(str_replace('\\', '/', $path));
        if (!str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        return 'file://'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
