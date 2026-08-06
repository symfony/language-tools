<?php

namespace Symfony\Lsp\Index;

enum SourceFileChange
{
    case ContentOnly;
    case FactsChanged;
    case Unchanged;
    case Untracked;
}
