<?php

namespace Symfony\Lsp\Feature\Messenger;

enum MessengerSymbolKind
{
    case Bus;
    case Message;
    case Transport;
}
