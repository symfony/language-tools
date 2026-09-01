<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Project\Project;

final class TwigComponentExtractor
{
    public function __construct(
        private readonly PhpParserInterface $phpParser,
        private readonly TwigComponentPhpExtractor $phpExtractor,
        private readonly TwigComponentTemplateExtractor $templateExtractor,
    ) {
    }

    public function extract(Project $project, SourceDocument $document): TwigComponentSourceFacts
    {
        return match ($document->languageId) {
            'php' => $this->phpExtractor->extract($document->uri, $document->text, $this->phpParser->parse($document->text)),
            'twig' => $this->templateExtractor->extract($project, $document->uri, $document->text),
            default => new TwigComponentSourceFacts($document->uri, [], []),
        };
    }
}
