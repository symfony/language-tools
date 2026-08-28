<?php

namespace Symfony\Lsp\Parser\Php;

enum PhpTypeKind: string
{
    case Class_ = 'class';
    case Interface_ = 'interface';
    case Trait_ = 'trait';
    case Enum = 'enum';
}
