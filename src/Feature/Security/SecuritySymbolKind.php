<?php

namespace Symfony\Lsp\Feature\Security;

enum SecuritySymbolKind: string
{
    case Firewall = 'firewall';
    case Provider = 'provider';
    case Role = 'role';
}
