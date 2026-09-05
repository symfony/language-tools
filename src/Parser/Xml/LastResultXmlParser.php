<?php

namespace Symfony\Lsp\Parser\Xml;

final class LastResultXmlParser implements XmlParserInterface
{
    private ?string $source = null;
    private ?XmlDocument $document = null;

    public function __construct(private readonly XmlParserInterface $parser)
    {
    }

    public function parse(string $source): XmlDocument
    {
        if ($source === $this->source && null !== $this->document) {
            return $this->document;
        }
        $document = $this->parser->parse($source);
        $this->source = $source;

        return $this->document = $document;
    }
}
