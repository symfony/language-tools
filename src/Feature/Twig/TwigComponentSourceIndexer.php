<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceFactsInterface;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<TwigComponentSourceFacts> */
final class TwigComponentSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(TwigComponentIndexRegistry $indexes, private readonly TwigComponentExtractor $extractor)
    {
        parent::__construct($indexes, 'twig_components_v2', TwigComponentSourceFacts::class);
    }

    protected function payloadElementClasses(): array
    {
        return [LiveComponentEvent::class, TwigComponent::class, TwigComponentAction::class, TwigComponentActionReference::class, TwigComponentReference::class];
    }

    protected function extract(Project $project, SourceDocument $document): TwigComponentSourceFacts
    {
        return $this->extractor->extract($project, $document);
    }

    protected function refreshRelevantFacts(SourceFactsInterface $facts): array
    {
        return [
            ...$facts->components,
            ...array_filter($facts->events, static fn (LiveComponentEvent $event): bool => $event->declaration),
        ];
    }

    protected function preserveDeclarations(SourceFactsInterface $healthy, SourceFactsInterface $current): TwigComponentSourceFacts
    {
        return new TwigComponentSourceFacts(
            $current->uri,
            $healthy->components,
            $current->references,
            $current->actionReferences,
            $healthy->events,
        );
    }
}
