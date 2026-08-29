<?php

namespace Symfony\Lsp\Parser\Php;

enum PhpAttributeTargetKind
{
    case Type;
    case Method;
    case Property;
}
