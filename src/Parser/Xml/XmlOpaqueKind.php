<?php

namespace Symfony\Lsp\Parser\Xml;

enum XmlOpaqueKind: string
{
    case Comment = 'comment';
    case ProcessingInstruction = 'processing-instruction';
    case Doctype = 'doctype';
    case Declaration = 'declaration';
}
