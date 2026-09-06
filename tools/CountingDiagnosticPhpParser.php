<?php

namespace Symfony\Lsp\Tools;

use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class CountingDiagnosticPhpParser implements PhpParserInterface
{
    public function __construct(private readonly PhpParserInterface $parser, private readonly DiagnosticParseCounter $counter)
    {
    }

    public function parse(string $source): PhpDocument
    {
        $this->counter->record($source);

        return $this->parser->parse($source);
    }
}
