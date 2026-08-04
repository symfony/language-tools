<?php

namespace Symfony\Lsp\Feature\Stimulus;

enum StimulusMemberKind: string
{
    case Action = 'action';
    case ClassName = 'class';
    case Outlet = 'outlet';
    case Target = 'target';
    case Value = 'value';
}
