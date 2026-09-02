<?php

namespace Symfony\Lsp\Index;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Project\Project;

final class PhpParseHealthResolver
{
    public function __construct(
        private readonly PhpParserInterface $parser,
        private readonly SourceOverlayHealthRegistry $registry,
    ) {
    }

    public function resolve(Project $project, Document $document): SourceParseHealth
    {
        $health = 'php' === $document->languageId && [] !== $this->parser->parse($document->text)->diagnostics
            ? SourceParseHealth::Partial
            : SourceParseHealth::Healthy;
        $this->registry->record($project, $document->uri, $health);

        return $health;
    }
}
