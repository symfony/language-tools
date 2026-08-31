<?php

namespace Symfony\Lsp\Feature\Twig;

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

    public function extract(Project $project, string $uri, string $languageId, string $text): TwigComponentSourceFacts
    {
        return match ($languageId) {
            'php' => $this->phpExtractor->extract($uri, $text, $this->phpParser->parse($text)),
            'twig' => $this->templateExtractor->extract($project, $uri, $text),
            default => new TwigComponentSourceFacts($uri, [], []),
        };
    }
}
