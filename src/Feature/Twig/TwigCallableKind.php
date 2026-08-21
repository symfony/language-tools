<?php

namespace Symfony\Lsp\Feature\Twig;

enum TwigCallableKind: string
{
    case Filter = 'filter';
    case Function = 'function';
}
