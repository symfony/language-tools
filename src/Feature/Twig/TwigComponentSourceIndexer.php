<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Index\AbstractSourceIndexer;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Project\Project;

/** @extends AbstractSourceIndexer<TwigComponentSourceFacts> */
final class TwigComponentSourceIndexer extends AbstractSourceIndexer
{
    public function __construct(private readonly TwigComponentIndexRegistry $indexes, private readonly TwigComponentExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'twig_components_v2';
    }

    public function payloadClasses(): array
    {
        return [LiveComponentEvent::class, TwigComponent::class, TwigComponentAction::class, TwigComponentActionReference::class, TwigComponentReference::class, TwigComponentSourceFacts::class];
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof TwigComponentSourceFacts) {
            throw new \UnexpectedValueException('The Twig component source facts are invalid.');
        }

        return [
            ...$data->components,
            ...array_filter($data->events, static fn (LiveComponentEvent $event): bool => $event->declaration),
        ];
    }

    protected function factsClass(): string
    {
        return TwigComponentSourceFacts::class;
    }

    protected function sourceIndex(Project $project): TwigComponentIndex
    {
        return $this->indexes->forProject($project);
    }

    protected function extract(Project $project, SourceDocument $document): TwigComponentSourceFacts
    {
        return $this->extractor->extract($project, $document->uri, $document->languageId, $document->text);
    }
}
