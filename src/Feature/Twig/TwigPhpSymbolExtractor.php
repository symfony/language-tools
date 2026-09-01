<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\PositionConverter;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;

final class TwigPhpSymbolExtractor
{
    public function __construct(
        private readonly PositionConverter $converter,
        private readonly PhpParserInterface $phpParser,
        private readonly TwigDocumentParser $twigParser,
        private readonly TwigPhpSymbolDeclarationExtractor $declarations,
        private readonly TwigPhpSymbolReferenceExtractor $references,
        private readonly TwigPhpSymbolCompletionContextResolver $completionContexts,
    ) {
    }

    public function extract(SourceDocument $document): ?TwigPhpSymbolSourceFacts
    {
        return match ($document->languageId) {
            'php' => new TwigPhpSymbolSourceFacts($document->uri, $this->declarations->extract($document->uri, $document->text, $this->phpParser->parse($document->text))),
            'twig' => new TwigPhpSymbolSourceFacts($document->uri, references: $this->references->extract($document->uri, $document->text, $this->twigParser->parse($document->text))),
            default => null,
        };
    }

    public function referenceAt(string $uri, string $text, int $offset): ?TwigPhpSymbolReference
    {
        foreach ($this->references->extract($uri, $text, $this->twigParser->parse($text)) as $reference) {
            if ($this->converter->containsByteOffset($text, $reference->range, $offset, inclusiveEnd: true)) {
                return $reference;
            }
        }

        return null;
    }

    public function completionContext(string $text, int $offset): ?TwigPhpSymbolCompletionContext
    {
        return $this->completionContexts->resolve($text, $offset);
    }
}
