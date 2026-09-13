<?php

namespace Symfony\Lsp\Parser\JavaScript;

enum JavaScriptTokenKind
{
    case Identifier;
    case Number;
    case String;
    case Template;
    case RegularExpression;
    case Punctuator;
    case Comment;
}
