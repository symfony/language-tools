<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\ClassNameKey;

final class TwigCallableKey
{
    public static function from(string $className, string $method): string
    {
        return ClassNameKey::from($className)."\0".strtolower($method);
    }
}
