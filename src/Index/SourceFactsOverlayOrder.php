<?php

namespace Symfony\Lsp\Index;

enum SourceFactsOverlayOrder
{
    case PreserveSavedPosition;
    case OverlaysLast;
}
