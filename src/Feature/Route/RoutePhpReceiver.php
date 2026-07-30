<?php

namespace Symfony\Lsp\Feature\Route;

final class RoutePhpReceiver
{
    public static function isSymfony(string $source): bool
    {
        $source = rtrim($source);

        if (preg_match('/\$this\s*$/', $source)) {
            return (bool) preg_match(
                '/class\s+\w+\s+extends\s+(?:AbstractController|[^\s{]*\\\\AbstractController)\b/s',
                $source,
            );
        }

        if (!preg_match('/\$(\w+)\s*$/', $source, $receiver)) {
            return false;
        }

        return (bool) preg_match(
            '/(?:RouterInterface|UrlGeneratorInterface)\s+\$'.preg_quote($receiver[1], '/').'\b/s',
            $source,
        );
    }

    private function __construct()
    {
    }
}
