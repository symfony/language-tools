<?php

namespace Symfony\Lsp\Project;

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

        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
