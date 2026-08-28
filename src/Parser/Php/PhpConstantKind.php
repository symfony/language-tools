<?php

namespace Symfony\Lsp\Parser\Php;

enum PhpConstantKind: string
{
    case ClassConstant = 'class constant';
    case EnumCase = 'enum case';
}
