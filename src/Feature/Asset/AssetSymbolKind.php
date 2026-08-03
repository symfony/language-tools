<?php

namespace Symfony\Lsp\Feature\Asset;

enum AssetSymbolKind: string
{
    case Asset = 'asset';
    case Entrypoint = 'importmap entrypoint';
}
