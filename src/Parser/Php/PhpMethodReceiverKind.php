<?php

namespace Symfony\Lsp\Parser\Php;

enum PhpMethodReceiverKind
{
    case Variable;
    case This;
    case ThisProperty;
    case Other;
}
