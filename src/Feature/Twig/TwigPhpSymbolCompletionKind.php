<?php

namespace Symfony\Lsp\Feature\Twig;

enum TwigPhpSymbolCompletionKind
{
    case ConstantType;
    case ConstantMember;
    case EnumType;
    case EnumCase;
}
