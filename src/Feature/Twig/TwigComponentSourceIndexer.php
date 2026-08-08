<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class TwigComponentSourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<TwigComponentSourceFacts>> */
    private array $facts = [];

    public function __construct(private readonly TwigComponentIndexRegistry $indexes, private readonly TwigComponentExtractor $extractor)
    {
    }

    public function name(): string
    {
        return 'twig_components_v2';
    }

    public function begin(Project $project): void
    {
        $this->facts[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): TwigComponentSourceFacts
    {
        return $this->add($project, $this->extract($project, $document));
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!$data instanceof TwigComponentSourceFacts) {
            throw new \UnexpectedValueException('The cached Twig component source facts are invalid.');
        }
        $this->add($project, $data);
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->indexes->forProject($project)->replace(...$this->facts[$key]);
        unset($this->facts[$key]);
    }

    public function replace(Project $project, SourceDocument $document): TwigComponentSourceFacts
    {
        $facts = $this->extract($project, $document);
        $this->indexes->forProject($project)->replaceSource($facts);

        return $facts;
    }

    public function runtimeDeclarations(mixed $data): array
    {
        if (!$data instanceof TwigComponentSourceFacts) {
            throw new \UnexpectedValueException('The Twig component source facts are invalid.');
        }

        return [
            ...$data->components(),
            ...array_filter($data->events(), static fn (LiveComponentEvent $event): bool => $event->isDeclaration()),
        ];
    }

    public function remove(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeSource($uri);
    }

    public function overlay(Project $project, Document $document): void
    {
        $this->indexes->forProject($project)->overlay($this->extractor->extract($project, $document->uri(), $document->languageId(), $document->text()));
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeOverlay($uri);
    }

    private function add(Project $project, TwigComponentSourceFacts $facts): TwigComponentSourceFacts
    {
        $this->facts[$project->rootPath()][] = $facts;

        return $facts;
    }

    private function extract(Project $project, SourceDocument $document): TwigComponentSourceFacts
    {
        return $this->extractor->extract($project, $document->uri(), $document->languageId(), $document->text());
    }
}
