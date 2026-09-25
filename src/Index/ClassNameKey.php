<?php

namespace Symfony\Lsp\Index;

final class ClassNameKey
{
    /** PHP class names are case-insensitive and may be written with a leading backslash. */
    public static function from(string $className): string
    {
        return strtolower(ltrim($className, '\\'));
    }
}
