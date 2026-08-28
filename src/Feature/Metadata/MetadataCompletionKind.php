<?php

namespace Symfony\Lsp\Feature\Metadata;

enum MetadataCompletionKind
{
    case FormOption;
    case FormProperty;
    case Constraint;
    case ConstraintOption;
    case SerializerGroup;
    case Property;
}
