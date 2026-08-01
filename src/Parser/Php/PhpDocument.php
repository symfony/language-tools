<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpDocument
{
    /**
     * @param list<PhpAttribute>  $attributes
     * @param list<PhpMethodCall> $methodCalls
     * @param list<PhpDiagnostic> $diagnostics
     */
    public function __construct(
        private readonly array $attributes,
        private readonly array $methodCalls,
        private readonly array $diagnostics,
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

    /** @return list<PhpDiagnostic> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
