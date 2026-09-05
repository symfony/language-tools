<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpLexicalScope
{
    /**
     * @param list<string> $parameterNames
     * @param list<string> $capturedVariableNames
     */
    public function __construct(
        public readonly PhpLexicalScopeKind $kind,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly array $parameterNames,
        public readonly array $capturedVariableNames,
        public readonly ?int $parentScopeStartOffset,
        public readonly bool $captureComplete,
    ) {
    }
}
