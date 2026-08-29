<?php

namespace Symfony\Lsp\Parser\Php;

enum PhpTypedVariableKind
{
    case Parameter;
    case Property;
    case PromotedProperty;
}
