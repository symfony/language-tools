<?php

namespace Symfony\Lsp\Parser\Php;

enum PhpLiteralKind
{
    case Array;
    case Boolean;
    case Float;
    case Integer;
    case Null;
    case String;
}
