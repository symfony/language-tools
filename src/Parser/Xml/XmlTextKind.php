<?php

namespace Symfony\Lsp\Parser\Xml;

enum XmlTextKind: string
{
    case Text = 'text';
    case Cdata = 'cdata';
}
