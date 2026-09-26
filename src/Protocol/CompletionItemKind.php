<?php

namespace Symfony\Lsp\Protocol;

/** The completion item kinds of the protocol the server offers items of. */
enum CompletionItemKind: int
{
    case Function = 3;
    case Field = 5;
    case Variable = 6;
    case Property = 10;
    case Value = 12;
    case Keyword = 14;
    case File = 17;
    case Reference = 18;
    case Event = 23;
}
