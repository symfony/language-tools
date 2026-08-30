<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<TemplateSourceFacts> */
final class TemplateSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        private readonly TemplateIndexRegistry $indexes,
        private readonly TemplateReferenceExtractor $extractor,
        private readonly TemplateNameResolver $nameResolver,
    ) {
    }

    public function name(): string
    {
        return 'templates';
    }

    public function payloadClasses(): array
    {
        return [TemplateDeclaration::class, TemplateReference::class, TemplateSourceFacts::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof TemplateSourceFacts) {
            throw new \UnexpectedValueException('The template source facts are invalid.');
        }

        return null === $data->declaration() ? [] : [$data->declaration()];
    }

    protected function factsClass(): string
    {
        return TemplateSourceFacts::class;
    }

    protected function sourceIndex(Project $project): TemplateSourceIndexAdapter
    {
        return new TemplateSourceIndexAdapter($this->indexes->forProject($project));
    }

    protected function extract(Project $project, SourceDocument $document): TemplateSourceFacts
    {
        return new TemplateSourceFacts(
            $document->uri,
            $this->declaration($project, $document->uri),
            $this->extractor->extract($document->uri, $document->languageId, $document->text),
        );
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
