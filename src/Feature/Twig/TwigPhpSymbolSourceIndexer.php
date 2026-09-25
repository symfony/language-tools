<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<TwigPhpSymbolSourceFacts> */
final class TwigPhpSymbolSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        TwigPhpSymbolSourceIndexRegistry $indexes,
        private readonly TwigPhpSymbolExtractor $extractor,
    ) {
        parent::__construct($indexes, 'twig_php_symbols', TwigPhpSymbolSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [
            TwigPhpSymbolDeclaration::class,
            TwigPhpSymbolKind::class,
            TwigPhpSymbolReference::class,
        ];
    }

    protected function extract(Project $project, SourceDocument $document): ?TwigPhpSymbolSourceFacts
    {
        return $this->extractor->extract($document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return [];
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): TwigPhpSymbolSourceFacts
    {
        return new TwigPhpSymbolSourceFacts($current->uri, $healthy->declarations, $current->references);
    }
}
