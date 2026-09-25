<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<TemplateSourceFacts> */
final class TemplateSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        TemplateIndexRegistry $indexes,
        private readonly TemplateReferenceExtractor $extractor,
        private readonly TemplateNameResolver $nameResolver,
    ) {
        parent::__construct($indexes, 'template', TemplateSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [TemplateDeclaration::class, TemplateReference::class];
    }

    protected function extract(Project $project, SourceDocument $document): TemplateSourceFacts
    {
        return new TemplateSourceFacts(
            $document->uri,
            $this->declaration($project, $document->uri),
            $this->extractor->extractCandidates($document),
        );
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return null === $facts->declaration ? [] : [$facts->declaration];
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): TemplateSourceFacts
    {
        return $current;
    }

    private function declaration(Project $project, string $uri): ?TemplateDeclaration
    {
        $name = $this->nameResolver->resolve($project, $uri);
        if (null === $name || 'twig' !== Path::getExtension($name, true)) {
            return null;
        }

        return new TemplateDeclaration($name, $uri, new Range(new Position(0, 0), new Position(0, 0)));
    }
}
