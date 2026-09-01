<?php

namespace Symfony\Lsp\Feature\Twig;

final class TwigCallableKey
{
    public static function from(string $className, string $method): string
    {
        return strtolower(ltrim($className, '\\'))."\0".strtolower($method);
    }
}
