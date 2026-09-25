<?php

namespace Symfony\Lsp\Feature;

enum DiagnosticCodeScope
{
    /** Proven by the project's files or by metadata every environment shares. */
    case EveryEnvironment;

    /** Proven only by the metadata the selected environment produced. */
    case SelectedEnvironment;
}
