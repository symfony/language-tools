<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<TwigCallableSourceFacts> */
final class TwigCallableSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        TwigCallableSourceIndexRegistry $indexes,
        private readonly TwigCallableDeclarationExtractor $extractor,
        private readonly TwigCallableReferenceExtractor $references,
    ) {
        parent::__construct($indexes, 'twig_callable', TwigCallableSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [TwigCallableArgumentReference::class, TwigCallableCallReference::class, TwigCallableDeclaration::class, TwigCallableKind::class, TwigCallableMethodParameter::class, TwigCallableSourceMethod::class, TwigCallableUsage::class];
    }

    protected function extract(Project $project, SourceDocument $document): ?TwigCallableSourceFacts
    {
        if ('php' === $document->languageId) {
            return $this->extractor->extract($document);
        }
        if ('twig' === $document->languageId) {
            return $this->references->extract($document);
        }

        return null;
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return $facts->declarations;
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): TwigCallableSourceFacts
    {
        return new TwigCallableSourceFacts($current->uri, $healthy->declarations, $current->usages, $current->calls, $healthy->methods);
    }
}
