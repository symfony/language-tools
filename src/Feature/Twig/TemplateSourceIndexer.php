<?php

namespace Symfony\Lsp\Feature\Twig;

use Symfony\Component\Filesystem\Path;
use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Document\Position;
use Symfony\Lsp\Document\Range;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class TemplateSourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<TemplateDeclaration>> */
    private array $templates = [];
    /** @var array<string, list<TemplateReference>> */
    private array $references = [];

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

    public function begin(Project $project): void
    {
        $this->templates[$project->rootPath()] = [];
        $this->references[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): TemplateSourceFacts
    {
        return $this->add($project, $this->extract($project, $document));
    }

    public function restore(Project $project, mixed $data): void
    {
        if (!$data instanceof TemplateSourceFacts) {
            throw new \UnexpectedValueException('The cached template source facts are invalid.');
        }

        $this->add($project, $data);
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $index = $this->indexes->forProject($project);
        $index->replaceSources(...$this->templates[$key]);
        $index->replaceReferences(...$this->references[$key]);
        unset($this->templates[$key], $this->references[$key]);
    }

    public function replace(Project $project, SourceDocument $document): TemplateSourceFacts
    {
        $facts = $this->extract($project, $document);
        $this->indexes->forProject($project)->replaceSource($facts->uri(), $facts->declaration(), $facts->references());

        return $facts;
    }

    public function remove(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeSource($uri);
    }

    public function overlay(Project $project, Document $document): void
    {
        $facts = $this->extract($project, new SourceDocument($document->uri(), $document->languageId(), $document->text()));
        $this->indexes->forProject($project)->overlay(
            $facts->uri(),
            $facts->declaration(),
            $facts->references(),
        );
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeOverlay($uri);
    }

    private function add(Project $project, TemplateSourceFacts $facts): TemplateSourceFacts
    {
        $key = $project->rootPath();
        if (null !== $facts->declaration()) {
            $this->templates[$key][] = $facts->declaration();
        }
        array_push($this->references[$key], ...$facts->references());

        return $facts;
    }

    private function extract(Project $project, SourceDocument $document): TemplateSourceFacts
    {
        return new TemplateSourceFacts(
            $document->uri(),
            $this->declaration($project, $document->uri()),
            $this->extractor->extract($document->uri(), $document->languageId(), $document->text()),
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
