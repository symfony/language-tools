<?php

namespace Symfony\Lsp\Parser\Php;

final class PhpExpressionParser
{
    public function __construct(private readonly TolerantPhpParser $parser)
    {
    }

    public function parse(string $expression): PhpDocument
    {
        return $this->parser->parse('<?php '.$expression.';');
    }
}
