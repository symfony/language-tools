<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

enum DependencyInjectionSymbolKind: string
{
    case Service = 'service';
    case Parameter = 'parameter';
}
