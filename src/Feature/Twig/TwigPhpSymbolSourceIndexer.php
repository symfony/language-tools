<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Parser\Php\PhpParserInterface;
use Symfony\Lsp\Parser\Twig\TwigDocumentParser;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<TwigPhpSymbolSourceFacts> */
final class TwigPhpSymbolSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        private readonly TwigPhpSymbolIndexRegistry $indexes,
        private readonly PhpParserInterface $phpParser,
        private readonly TwigDocumentParser $twigParser,
        private readonly TwigPhpSymbolDeclarationExtractor $declarations,
        private readonly TwigPhpSymbolReferenceExtractor $references,
    ) {
    }

    public function name(): string
    {
        return 'twig_php_symbols';
    }

    public function payloadClasses(): array
    {
        return [
            TwigPhpSymbolDeclaration::class,
            TwigPhpSymbolKind::class,
            TwigPhpSymbolReference::class,
            TwigPhpSymbolSourceFacts::class,
        ];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        return [];
    }

    protected function factsClass(): string
    {
        return TwigPhpSymbolSourceFacts::class;
    }

    protected function sourceIndex(Project $project): TwigPhpSymbolIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): ?TwigPhpSymbolSourceFacts
    {
        return match ($document->languageId) {
            'php' => new TwigPhpSymbolSourceFacts($document->uri, $this->declarations->extract($document->uri, $document->text, $this->phpParser->parse($document->text))),
            'twig' => new TwigPhpSymbolSourceFacts($document->uri, references: $this->references->extract($document->uri, $document->text, $this->twigParser->parse($document->text))),
            default => null,
        };
    }
}
