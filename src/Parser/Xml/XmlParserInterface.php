<?php

namespace Symfony\Lsp\Parser\Xml;

interface XmlParserInterface
{
    public function parse(string $source): XmlDocument;
}
