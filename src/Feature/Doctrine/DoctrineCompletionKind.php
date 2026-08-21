<?php

namespace Symfony\Lsp\Feature\Doctrine;

enum DoctrineCompletionKind
{
    case EntityTypeField;
    case RepositoryCriteria;
}
