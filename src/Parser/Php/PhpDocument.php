<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpDocument
{
    /**
     * @param list<PhpAttribute>       $attributes
     * @param list<PhpMethodCall>      $methodCalls
     * @param list<PhpTypeDeclaration> $typeDeclarations
     * @param list<PhpDiagnostic>      $diagnostics
     * @param array<string, string>    $imports
     */
    public function __construct(
        private readonly array $attributes,
        private readonly array $methodCalls,
        private readonly array $typeDeclarations,
        private readonly array $diagnostics,
        private readonly string $namespace = '',
        private readonly array $imports = [],
    ) {
    }

    /** @return list<PhpAttribute> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /** @return list<PhpMethodCall> */
    public function methodCalls(): array
    {
        return $this->methodCalls;
    }

    /** @return list<PhpTypeDeclaration> */
    public function typeDeclarations(): array
    {
        return $this->typeDeclarations;
    }

    /** @return list<PhpDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    /** @return array<string, string> */
    public function imports(): array
    {
        return $this->imports;
    }

    public function resolveName(string $name): string
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
