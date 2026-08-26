<?php

namespace Symfony\Lsp\Feature\Console;

enum ConsoleInputKind: string
{
    case Argument = 'argument';
    case Option = 'option';
}
