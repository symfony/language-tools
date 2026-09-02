<?php

namespace Symfony\Lsp\Parser\Php;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceOverlayHealthRegistry;
use Symfony\Lsp\Index\SourceParseHealth;
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
