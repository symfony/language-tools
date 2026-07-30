<?php

namespace Symfony\Lsp\Feature\DependencyInjection;

use Symfony\Lsp\Document\Document;
use Symfony\Lsp\Index\SourceDocument;
use Symfony\Lsp\Index\SourceIndexProviderInterface;
use Symfony\Lsp\Project\Project;

final class DependencyInjectionSourceIndexer implements SourceIndexProviderInterface
{
    /** @var array<string, list<DependencyInjectionSourceFacts>> */
    private array $sources = [];

    public function __construct(
        private readonly DependencyInjectionSourceIndexRegistry $indexes,
        private readonly YamlDependencyInjectionExtractor $yamlExtractor,
        private readonly PhpAutowireReferenceExtractor $autowireExtractor,
        private readonly PhpClassDeclarationExtractor $classExtractor,
    ) {
    }

    public function begin(Project $project): void
    {
        $this->sources[$project->rootPath()] = [];
    }

    public function index(Project $project, SourceDocument $document): void
    {
        if (null !== $facts = $this->extract($document->uri(), $document->languageId(), $document->text())) {
            $this->sources[$project->rootPath()][] = $facts;
        }
    }

    public function finish(Project $project): void
    {
        $key = $project->rootPath();
        $this->indexes->forProject($project)->replace(...$this->sources[$key]);
        unset($this->sources[$key]);
    }

    public function overlay(Project $project, Document $document): void
    {
        $facts = $this->extract($document->uri(), $document->languageId(), $document->text());
        if (null !== $facts) {
            $this->indexes->forProject($project)->overlay($facts);
        }
    }

    public function removeOverlay(Project $project, string $uri): void
    {
        $this->indexes->forProject($project)->removeOverlay($uri);
    }

    private function extract(string $uri, string $languageId, string $text): ?DependencyInjectionSourceFacts
    {
        if ('yaml' === $languageId) {
            return $this->yamlExtractor->extract($uri, $text);
        }
        if ('php' !== $languageId) {
            return null;
        }

        return new DependencyInjectionSourceFacts(
            $uri,
            references: $this->autowireExtractor->extract($uri, $text),
            classes: $this->classExtractor->extract($uri, $text),
        );
    }
}
