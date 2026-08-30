<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpNameContext
{
    /** @param array<string, string> $imports */
    public function __construct(public readonly string $namespace = '', public readonly array $imports = [])
    {
    }

    public function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        if (str_starts_with($name, 'namespace\\')) {
            $name = substr($name, \strlen('namespace\\'));

            return '' === $this->namespace ? $name : $this->namespace.'\\'.$name;
        }
        $separator = strpos($name, '\\');
        $head = false === $separator ? $name : substr($name, 0, $separator);
        if (isset($this->imports[$head])) {
            return $this->imports[$head].(false === $separator ? '' : substr($name, $separator));
        }

        return '' === $this->namespace ? $name : $this->namespace.'\\'.$name;
    }
}
