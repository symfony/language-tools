<?php

namespace Symfony\Lsp\Project;

enum TrustStatus
{
    case Unknown;
    case Trusted;
    case Untrusted;
}
