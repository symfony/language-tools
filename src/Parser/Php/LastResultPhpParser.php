<?php

namespace Symfony\Lsp\Parser\Php;

final class LastResultPhpParser implements PhpParserInterface
{
    private ?string $source = null;
    private ?PhpDocument $document = null;

    public function __construct(private readonly PhpParserInterface $parser)
    {
    }

    public function parse(string $source): PhpDocument
    {
        if ($source === $this->source && null !== $this->document) {
            return $this->document;
        }
        $document = $this->parser->parse($source);
        $this->source = $source;
        $this->document = $document;

        return $document;
    }
}
