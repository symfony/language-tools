<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<TwigPhpSymbolSourceFacts> */
final class TwigPhpSymbolSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        private readonly TwigPhpSymbolIndexRegistry $indexes,
        private readonly TwigPhpSymbolExtractor $extractor,
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
        return $this->extractor->extract($document->uri(), $document->languageId(), $document->text());
    }
}
