<?php

namespace Symfony\Lsp\Feature\Twig;

enum TwigPhpSymbolKind: string
{
    case Class_ = 'class';
    case Interface_ = 'interface';
    case Trait_ = 'trait';
    case Enum = 'enum';
    case ClassConstant = 'class constant';
    case EnumCase = 'enum case';

    public function isType(): bool
    {
        return \in_array($this, [self::Class_, self::Interface_, self::Trait_, self::Enum], true);
    }

    public function isMember(): bool
    {
        return !$this->isType();
    }
}
