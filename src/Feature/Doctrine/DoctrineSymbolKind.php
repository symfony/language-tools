<?php

namespace Symfony\Lsp\Feature\Doctrine;

enum DoctrineSymbolKind: string
{
    case Entity = 'entity';
    case Field = 'field';
    case Repository = 'repository';
}
