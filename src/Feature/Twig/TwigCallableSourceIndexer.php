<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<TwigCallableSourceFacts> */
final class TwigCallableSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(
        private readonly TwigCallableIndexRegistry $indexes,
        private readonly TwigCallableDeclarationExtractor $extractor,
        private readonly TwigCallableReferenceExtractor $references,
    ) {
    }

    public function name(): string
    {
        return 'twig_callables';
    }

    public function payloadClasses(): array
    {
        return [TwigCallableDeclaration::class, TwigCallableKind::class, TwigCallableSourceFacts::class, TwigCallableUsage::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (null === $data) {
            return [];
        }
        if (!$data instanceof TwigCallableSourceFacts) {
            throw new \UnexpectedValueException('The Twig callable source facts are invalid.');
        }

        return $data->declarations();
    }

    protected function factsClass(): string
    {
        return TwigCallableSourceFacts::class;
    }

    protected function sourceIndex(Project $project): TwigCallableIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): ?TwigCallableSourceFacts
    {
        if ('php' === $document->languageId()) {
            return $this->extractor->extract($document->uri(), $document->text());
        }
        if ('twig' === $document->languageId()) {
            return new TwigCallableSourceFacts($document->uri(), [], $this->references->all($document->uri(), $document->text()));
        }

        return null;
    }
}
