<?php

namespace Symfony\Lsp\Index;

enum SourceFileChange
{
    case Changed;
    case Unchanged;
    case Untracked;
}
