<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class PhpParseHealthResolver
{
    public function __construct(private readonly PhpParserInterface $parser)
    {
    }

    public function resolve(Document $document): SourceParseHealth
    {
        return 'php' === $document->languageId && [] !== $this->parser->parse($document->text)->diagnostics
            ? SourceParseHealth::Partial
            : SourceParseHealth::Healthy;
    }
}
