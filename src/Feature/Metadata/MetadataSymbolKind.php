<?php

namespace Symfony\Lsp\Feature\Metadata;

enum MetadataSymbolKind: string
{
    case SerializerGroup = 'serializer group';
    case Constraint = 'validation constraint';
    case MappedClass = 'mapped class';
    case Property = 'mapped property';
}
