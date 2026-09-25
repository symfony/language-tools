<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Parser\Xml\XmlDocument;
use Symfony\Lsp\Parser\Xml\XmlParserInterface;

final class RecordingXmlParser implements XmlParserInterface
{
    /** @var list<string> */
    public array $sources = [];

    public function __construct(private readonly ?XmlParserInterface $parser = null)
    {
    }

    public function parse(string $source): XmlDocument
    {
        $this->sources[] = $source;

        return $this->parser?->parse($source) ?? new XmlDocument([]);
    }
}
