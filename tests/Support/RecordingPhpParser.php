<?php

namespace Symfony\Lsp\Tests\Support;

use Symfony\Lsp\Parser\Php\PhpDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;

final class RecordingPhpParser implements PhpParserInterface
{
    /** @var list<string> */
    public array $sources = [];

    public function __construct(private readonly ?PhpParserInterface $parser = null)
    {
    }

    public function parse(string $source): PhpDocument
    {
        $this->sources[] = $source;

        return $this->parser?->parse($source) ?? new PhpDocument([], [], [], []);
    }
}
