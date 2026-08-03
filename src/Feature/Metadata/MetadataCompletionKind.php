<?php

namespace Symfony\Lsp\Feature\Metadata;

enum MetadataCompletionKind
{
    case FormOption;
    case Constraint;
    case ConstraintOption;
    case SerializerGroup;
    case Property;
}
