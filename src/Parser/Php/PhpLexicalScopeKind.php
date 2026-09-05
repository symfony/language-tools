<?php

namespace Symfony\Lsp\Parser\Php;

enum PhpLexicalScopeKind: string
{
    case Closure = 'closure';
    case ArrowFunction = 'arrow_function';
}
